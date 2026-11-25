<?php

namespace App\Services;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plant;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdministrationSecurityService
{
    public function __construct(
        private readonly TenantContext $context
    ) {}

    /**
     * Ejecuta una operación administrativa dentro de una
     * transacción y serializa las modificaciones por organización.
     */
    public function transaction(
        User $actor,
        Closure $callback
    ): mixed {
        $organizationId = $this->context->requireId();

        try {
            return DB::transaction(function () use ($actor, $callback, $organizationId) {
                Organization::query()
                    ->whereKey($organizationId)
                    ->where('type', Organization::TYPE_CLIENT)
                    ->where('status', true)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->context->flushPermissionCache(
                    null,
                    $organizationId
                );

                $this->ensureActiveMember($actor);

                $result = $callback();

                $this->context->flushPermissionCache(
                    null,
                    $organizationId
                );

                $this->ensureAdministrativeAccessRemains();

                return $result;
            });
        } finally {
            $this->context->flushPermissionCache(
                null,
                $organizationId
            );
        }
    }

    /**
     * Comprueba que el usuario tenga una cuenta y una
     * membresía activas en la organización actual.
     */
    public function ensureActiveMember(User $user): void
    {
        $organizationId = $this->context->requireId();

        $activeAccount = User::query()
            ->whereKey($user->id)
            ->where('status', true)
            ->exists();

        abort_unless(
            $activeAccount
            && OrganizationMembership::findActive(
                $user->id,
                $organizationId
            ) !== null,
            403,
            'El usuario no tiene una membresía activa.'
        );
    }

    /**
     * Exige todos los permisos indicados.
     */
    public function authorize(
        User $actor,
        Permission ...$permissions
    ): void {
        $this->ensureActiveMember($actor);

        abort_if(
            $permissions === [],
            403,
            'No se ha definido una autorización válida.'
        );

        foreach ($permissions as $permission) {
            abort_unless(
                $actor->hasPermission($permission),
                403,
                'No tienes permisos para realizar esta operación.'
            );
        }
    }

    /**
     * Exige al menos uno de los permisos indicados.
     */
    public function authorizeAny(
        User $actor,
        Permission ...$permissions
    ): void {
        $this->ensureActiveMember($actor);

        foreach ($permissions as $permission) {
            if ($actor->hasPermission($permission)) {
                return;
            }
        }

        abort(
            403,
            'No tienes permisos para realizar esta operación.'
        );
    }

    /**
     * Comprueba que un recurso pertenezca al tenant activo.
     */
    public function ensureTenant(Model $model): void
    {
        abort_unless(
            $model->getAttribute('organization_id')
            === $this->context->requireId(),
            404,
            'El recurso solicitado no está disponible.'
        );
    }

    /**
     * Obtiene la membresía del usuario exclusivamente
     * dentro de la organización actual.
     */
    public function getMembership(
        string $userId
    ): OrganizationMembership {
        return OrganizationMembership::query()
            ->where(
                'organization_id',
                $this->context->requireId()
            )
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    /**
     * Impide modificar roles base del sistema.
     */
    public function ensureMutableRole(Role $role): void
    {
        $this->ensureTenant($role);

        if ($role->system_key !== null) {
            throw ValidationException::withMessages([
                'role_id' => [
                    'Los roles base del sistema no pueden modificarse.',
                ],
            ]);
        }
    }

    /**
     * Comprueba que el actor posea todos los permisos
     * que pretende conceder o administrar.
     *
     * @param  list<string>  $permissions
     */
    public function ensureCanDelegatePermissions(
        User $actor,
        array $permissions
    ): void {
        $this->ensureActiveMember($actor);

        foreach ($permissions as $value) {
            $permission = Permission::tryFrom($value);

            if ($permission === null) {
                throw ValidationException::withMessages([
                    'permissions' => [
                        'Se ha proporcionado un permiso inválido.',
                    ],
                ]);
            }

            abort_unless(
                $actor->hasPermission($permission),
                403,
                'No puedes administrar permisos que no posees.'
            );
        }
    }

    /**
     * Obtiene los permisos configurados de un rol,
     * independientemente de su estado.
     *
     * @return list<string>
     */
    public function getRolePermissions(Role $role): array
    {
        $this->ensureTenant($role);

        return RolePermission::query()
            ->where(
                'organization_id',
                $this->context->requireId()
            )
            ->where('role_id', $role->id)
            ->pluck('permission')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Comprueba que el actor pueda administrar un rol
     * sin superar su propio nivel de autorización.
     */
    public function ensureCanManageRole(
        User $actor,
        Role $role
    ): void {
        $this->authorize(
            $actor,
            Permission::RolesManage
        );

        $this->ensureMutableRole($role);

        $this->ensureCanDelegatePermissions(
            $actor,
            $this->getRolePermissions($role)
        );
    }

    /**
     * Comprueba que un rol pueda asignarse al usuario.
     */
    public function ensureCanAssignRole(
        User $actor,
        Role $role
    ): void {
        $this->authorize(
            $actor,
            Permission::UsersManage,
            Permission::RolesManage
        );

        $this->ensureTenant($role);

        if (! $role->status) {
            throw ValidationException::withMessages([
                'role_id' => [
                    'No se puede asignar un rol inactivo.',
                ],
            ]);
        }

        $this->ensureCanDelegatePermissions(
            $actor,
            $this->getRolePermissions($role)
        );

        $this->ensureCanDelegateSystemRole($actor, $role);
    }

    /**
     * Comprueba que un usuario pueda ser administrado
     * por el actor dentro de la organización actual.
     *
     * Incluye roles inactivos para evitar que un usuario
     * limitado modifique asignaciones privilegiadas latentes.
     */
    public function ensureCanManageMember(
        User $actor,
        User $target
    ): void {
        $this->ensureActiveMember($actor);

        $this->getMembership($target->id);

        $organizationId = $this->context->requireId();

        $roles = $target->roles()
            ->wherePivot(
                'organization_id',
                $organizationId
            )
            ->where(
                'roles.organization_id',
                $organizationId
            )
            ->get();

        foreach ($roles as $role) {
            $this->ensureCanDelegatePermissions(
                $actor,
                $this->getRolePermissions($role)
            );

            $this->ensureCanDelegateSystemRole(
                $actor,
                $role
            );
        }
    }

    /**
     * Impide que un usuario revoque su propia membresía.
     */
    public function ensureNotSelfRevocation(
        User $actor,
        User $target
    ): void {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'membership' => [
                    'No puedes revocar tu propia membresía.',
                ],
            ]);
        }
    }

    /**
     * Comprueba el alcance administrativo de una planta.
     *
     * locations.view_all amplía el alcance administrativo,
     * pero no concede acceso operativo a inspecciones.
     */
    public function ensurePlantScope(
        User $actor,
        Plant $plant
    ): void {
        $this->ensureActiveMember($actor);
        $this->ensureTenant($plant);

        abort_unless(
            $actor->hasPermission(
                Permission::LocationsViewAll
            ) || $actor->hasPlantAccess($plant->id),
            403,
            'No tienes acceso administrativo a esta planta.'
        );
    }

    /**
     * Comprueba el alcance administrativo de un área.
     */
    public function ensureAreaScope(
        User $actor,
        Area $area
    ): void {
        $this->ensureActiveMember($actor);
        $this->ensureTenant($area);

        abort_unless(
            $actor->hasPermission(
                Permission::LocationsViewAll
            )
            || $actor->hasPlantAccess($area->plant_id)
            || $actor->hasAreaAccess($area->id),
            403,
            'No tienes acceso administrativo a esta área.'
        );
    }

    /**
     * Comprueba que una planta pueda asignarse.
     */
    public function ensureAssignablePlant(
        User $actor,
        Plant $plant
    ): void {
        $this->authorize(
            $actor,
            Permission::UsersManage,
            Permission::PlantsManage
        );

        $this->ensurePlantScope($actor, $plant);

        if (! $plant->status) {
            throw ValidationException::withMessages([
                'plant_ids' => [
                    'No se pueden asignar plantas inactivas.',
                ],
            ]);
        }
    }

    /**
     * Comprueba que un área y su planta estén activas
     * antes de permitir su asignación.
     */
    public function ensureAssignableArea(
        User $actor,
        Area $area
    ): void {
        $this->authorize(
            $actor,
            Permission::UsersManage,
            Permission::PlantsManage
        );

        $this->ensureAreaScope($actor, $area);

        $activePlant = Plant::query()
            ->where(
                'organization_id',
                $this->context->requireId()
            )
            ->whereKey($area->plant_id)
            ->where('status', true)
            ->exists();

        if (! $area->status || ! $activePlant) {
            throw ValidationException::withMessages([
                'area_ids' => [
                    'No se pueden asignar áreas no disponibles.',
                ],
            ]);
        }
    }

    /**
     * Comprueba que la organización conserve al menos
     * un administrador base operativo.
     */
    public function ensureAdministrativeAccessRemains(): void
    {
        $organizationId = $this->context->requireId();

        $this->context->flushPermissionCache(
            null,
            $organizationId
        );

        $administratorIds = DB::table('user_roles')
            ->join('roles', function ($join) {
                $join->on(
                    'roles.id',
                    '=',
                    'user_roles.role_id'
                )->on(
                    'roles.organization_id',
                    '=',
                    'user_roles.organization_id'
                );
            })
            ->join(
                'users',
                'users.id',
                '=',
                'user_roles.user_id'
            )
            ->where(
                'user_roles.organization_id',
                $organizationId
            )
            ->where(
                'roles.organization_id',
                $organizationId
            )
            ->where('roles.system_key', 'admin')
            ->where('roles.status', true)
            ->where('users.status', true)
            ->distinct()
            ->pluck('user_roles.user_id');

        $administrators = User::query()
            ->whereIn('id', $administratorIds)
            ->get();

        foreach ($administrators as $administrator) {
            if (
                OrganizationMembership::findActive(
                    $administrator->id,
                    $organizationId
                ) === null
            ) {
                continue;
            }

            if (
                $administrator->hasPermission(
                    Permission::UsersManage
                )
                && $administrator->hasPermission(
                    Permission::RolesManage
                )
                && $administrator->hasPermission(
                    Permission::MembershipsManage
                )
            ) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'administration' => [
                'La organización debe conservar al menos un administrador operativo.',
            ],
        ]);
    }

    /**
     * Protege los privilegios especiales de los roles base.
     */
    public function ensureCanDelegateSystemRole(
        User $actor,
        Role $role
    ): void {
        $this->ensureActiveMember($actor);
        $this->ensureTenant($role);

        $organizationId = $this->context->requireId();

        if ($role->system_key === 'admin') {
            $isBaseAdministrator = $actor->roles()
                ->wherePivot('organization_id', $organizationId)
                ->where('roles.organization_id', $organizationId)
                ->where('roles.system_key', 'admin')
                ->where('roles.status', true)
                ->exists();

            abort_unless(
                $isBaseAdministrator,
                403,
                'Solo un administrador base puede administrar este rol.'
            );
        }

        if ($role->system_key === 'supervisor') {
            abort_unless(
                $actor->hasEligibleVerifierRole(),
                403,
                'No puedes conceder los privilegios de este rol.'
            );
        }
    }
}
