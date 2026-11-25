<?php

namespace App\Services;

use App\Authorization\Permission;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Role;
use App\Models\UserRole;
use App\Support\Audit\AuditLogger;
use App\Support\Locations\LocationReadScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministrationUserService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AdministrationSecurityService $security,
        private readonly AuditLogger $audit
    ) {}

    /**
     * Lista los usuarios pertenecientes a la organización activa.
     */
    public function index(
        User $actor,
        array $filters = []
    ): array {
        $this->security->authorizeAny(
            $actor,
            Permission::UsersManage,
            Permission::MembershipsManage
        );

        $organizationId = $this->context->requireId();

        $query = $this->membershipQuery();

        if (isset($filters['status'])) {
            $query->where(
                'organization_memberships.status',
                $filters['status']
            );
        }

        if (isset($filters['q'])) {
            $search = LocationReadScope::escapeLike(
                trim($filters['q'])
            );

            $query->whereHas(
                'user',
                function (Builder $builder) use ($search): void {
                    $builder
                        ->whereRaw(
                            "name LIKE ? ESCAPE '!'",
                            ['%'.$search.'%']
                        )
                        ->orWhereRaw(
                            "email LIKE ? ESCAPE '!'",
                            ['%'.$search.'%']
                        );
                }
            );
        }

        if (isset($filters['role_id'])) {
            $roleId = $filters['role_id'];

            $query->whereHas(
                'user.roles',
                function (Builder $builder) use (
                    $organizationId,
                    $roleId
                ): void {
                    $builder
                        ->where(
                            'roles.id',
                            $roleId
                        )
                        ->where(
                            'roles.organization_id',
                            $organizationId
                        )
                        ->where(
                            'user_roles.organization_id',
                            $organizationId
                        );
                }
            );
        }

        $memberships = $query
            ->orderBy(
                User::query()
                    ->select('name')
                    ->whereColumn(
                        'users.id',
                        'organization_memberships.user_id'
                    )
            )
            ->orderBy('organization_memberships.id')
            ->paginate(
                $filters['per_page'] ?? 15,
                ['*'],
                'page',
                $filters['page'] ?? 1
            );

        return [
            'data' => $memberships
                ->getCollection()
                ->map(
                    fn (OrganizationMembership $membership): array => $this->serializeMembership(
                        $actor,
                        $membership
                    )
                )
                ->values()
                ->all(),

            'meta' => [
                'current_page' => $memberships->currentPage(),
                'per_page' => $memberships->perPage(),
                'total' => $memberships->total(),
                'last_page' => $memberships->lastPage(),
            ],
        ];
    }

    /**
     * Consulta un usuario dentro de la organización activa.
     */
    public function show(
        User $actor,
        string $userId
    ): array {
        $this->security->authorizeAny(
            $actor,
            Permission::UsersManage,
            Permission::MembershipsManage
        );

        $membership = $this->membershipQuery()
            ->where('user_id', $userId)
            ->firstOrFail();

        return $this->serializeMembership(
            $actor,
            $membership
        );
    }

    /**
     * Crea una identidad global y su membresía inicial.
     */
    public function create(
        User $actor,
        array $data
    ): array {
        $membership = $this->security->transaction(
            $actor,
            function () use ($actor, $data) {
                $this->security->authorize(
                    $actor,
                    Permission::UsersManage,
                    Permission::MembershipsManage
                );

                $organizationId = $this->context->requireId();

                $email = Str::lower(
                    trim($data['email'])
                );

                if (
                    User::query()
                        ->where('email', $email)
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'email' => [
                            'No es posible registrar este correo electrónico.',
                        ],
                    ]);
                }

                try {
                    $user = new User;

                    $user->id = (string) Str::uuid7();
                    $user->name = trim($data['name']);
                    $user->email = $email;
                    $user->password = $data['password'];

                    // Columna heredada de compatibilidad.
                    // No representa una membresía.
                    $user->organization_id = $organizationId;

                    $user->status = true;

                    $user->save();
                } catch (UniqueConstraintViolationException $exception) {
                    if (
                        User::query()
                            ->where('email', $email)
                            ->exists()
                    ) {
                        throw ValidationException::withMessages([
                            'email' => [
                                'No es posible registrar este correo electrónico.',
                            ],
                        ]);
                    }

                    throw $exception;
                }

                $membership = OrganizationMembership::create([
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                    'status' => 'active',
                    'revoked_at' => null,
                ]);

                $this->audit->record(
                    'user.created',
                    $user,
                    [],
                    [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'status' => (bool) $user->status,
                    ]
                );

                $this->audit->record(
                    'membership.created',
                    $membership,
                    [],
                    $this->membershipSnapshot($membership)
                );

                return $membership;
            }
        );

        return $this->show(
            $actor,
            $membership->user_id
        );
    }

    /**
     * Revoca o reactiva una membresía sin modificar
     * la identidad global del usuario.
     */
    public function updateMembershipStatus(
        User $actor,
        string $userId,
        string $status
    ): array {
        if (! in_array($status, ['active', 'revoked'], true)) {
            throw ValidationException::withMessages([
                'status' => [
                    'El estado de membresía no es válido.',
                ],
            ]);
        }

        $this->security->transaction(
            $actor,
            function () use ($actor, $userId, $status): void {
                $this->security->authorize(
                    $actor,
                    Permission::MembershipsManage
                );

                $membership = OrganizationMembership::query()
                    ->forOrganization(
                        $this->context->requireId()
                    )
                    ->forUser($userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $target = User::query()
                    ->whereKey($membership->user_id)
                    ->firstOrFail();

                $this->security->ensureCanManageMember(
                    $actor,
                    $target
                );

                if ($status === 'revoked') {
                    $this->security->ensureNotSelfRevocation(
                        $actor,
                        $target
                    );
                }

                // Operación idempotente.
                if ($membership->status === $status) {
                    return;
                }

                if ($status === 'active') {
                    if (! $target->status) {
                        throw ValidationException::withMessages([
                            'status' => [
                                'La cuenta del usuario no está disponible.',
                            ],
                        ]);
                    }

                    $this->ensureCanRestoreLocations(
                        $actor,
                        $target
                    );
                }

                $oldValues = $this->membershipSnapshot(
                    $membership
                );

                $membership->status = $status;

                $membership->revoked_at =
                    $status === 'revoked'
                        ? now()
                        : null;

                $membership->save();

                $this->context->flushPermissionCache(
                    $target->id,
                    $this->context->requireId()
                );

                $this->audit->record(
                    $status === 'revoked'
                        ? 'membership.revoked'
                        : 'membership.reactivated',
                    $membership,
                    $oldValues,
                    $this->membershipSnapshot($membership)
                );
            }
        );

        return $this->show(
            $actor,
            $userId
        );
    }

    /**
     * Consulta base de membresías y relaciones.
     *
     * Nunca utiliza users.organization_id para determinar
     * la pertenencia de un usuario a la organización.
     */
    public function replaceRoles(User $actor, string $userId, array $roleIds): array
    {
        return $this->security->transaction($actor, function () use ($actor, $userId, $roleIds): array {
            $this->security->authorize($actor, Permission::UsersManage, Permission::RolesManage);
            $organizationId = $this->context->requireId();
            $membership = OrganizationMembership::query()->forOrganization($organizationId)
                ->forUser($userId)->lockForUpdate()->firstOrFail();
            $target = User::query()->whereKey($membership->user_id)->firstOrFail();
            $this->security->ensureActiveMember($target);
            $this->security->ensureCanManageMember($actor, $target);

            $current = $target->roles()->wherePivot('organization_id', $organizationId)
                ->where('roles.organization_id', $organizationId)->pluck('roles.id')->all();
            $roles = Role::query()->where('organization_id', $organizationId)
                ->whereIn('id', $roleIds)->orderBy('id')->lockForUpdate()->get();
            abort_unless($roles->count() === count($roleIds), 404, 'El recurso solicitado no está disponible.');

            $added = array_values(array_diff($roleIds, $current));
            $removed = array_values(array_diff($current, $roleIds));
            foreach ($roles as $role) {
                if (in_array($role->id, $added, true)) {
                    $this->security->ensureCanAssignRole($actor, $role);
                }
            }

            foreach ($roles as $role) {
                if (in_array($role->id, $added, true)) {
                    $target->assignRole($role);
                }
            }

            UserRole::query()->where('organization_id', $organizationId)
                ->where('user_id', $userId)->whereIn('role_id', $removed)->delete();

            $this->context->flushPermissionCache($userId, $organizationId);
            sort($current);
            sort($roleIds);
            if ($current !== $roleIds) {
                $this->audit->record('user.roles_updated', $membership,
                    ['role_ids' => $current], ['role_ids' => $roleIds]);
            }

            // No volver a autorizar después de una posible reducción de permisos propios.
            return $this->serializeMembership($actor, $this->membershipQuery()
                ->where('user_id', $userId)->firstOrFail());
        });
    }

    private function membershipQuery(): Builder
    {
        $organizationId = $this->context->requireId();

        return OrganizationMembership::query()
            ->forOrganization($organizationId)
            ->whereHas('user')
            ->with([
                'user',

                'user.roles' => function ($query) use (
                    $organizationId
                ): void {
                    $query
                        ->where(
                            'roles.organization_id',
                            $organizationId
                        )
                        ->where(
                            'user_roles.organization_id',
                            $organizationId
                        )
                        ->orderBy('roles.name')
                        ->orderBy('roles.id');
                },

                'user.plants' => function ($query) use (
                    $organizationId
                ): void {
                    $query
                        ->where(
                            'plants.organization_id',
                            $organizationId
                        )
                        ->where(
                            'user_plants.organization_id',
                            $organizationId
                        )
                        ->orderBy('plants.name')
                        ->orderBy('plants.id');
                },

                'user.areas' => function ($query) use (
                    $organizationId
                ): void {
                    $query
                        ->where(
                            'areas.organization_id',
                            $organizationId
                        )
                        ->where(
                            'user_areas.organization_id',
                            $organizationId
                        )
                        ->orderBy('areas.name')
                        ->orderBy('areas.id');
                },
            ]);
    }

    /**
     * Construye una respuesta explícita, evitando exponer
     * atributos internos o sensibles del modelo User.
     */
    private function serializeMembership(
        User $actor,
        OrganizationMembership $membership
    ): array {
        $user = $membership->user;

        $canViewLocations = $actor->hasPermission(
            Permission::PlantsView
        );

        $plants = [];
        $areas = [];

        if ($canViewLocations) {
            $plants = $user->plants
                ->filter(
                    fn ($plant): bool => LocationReadScope::canViewPlant(
                        $actor,
                        $plant->id
                    )
                )
                ->map(fn ($plant): array => [
                    'id' => $plant->id,
                    'name' => $plant->name,
                    'code' => $plant->code,
                    'status' => (bool) $plant->status,
                ])
                ->values()
                ->all();

            $areas = $user->areas
                ->filter(
                    fn ($area): bool => LocationReadScope::canViewArea(
                        $actor,
                        $area
                    )
                )
                ->map(fn ($area): array => [
                    'id' => $area->id,
                    'plant_id' => $area->plant_id,
                    'name' => $area->name,
                    'code' => $area->code,
                    'status' => (bool) $area->status,
                ])
                ->values()
                ->all();
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,

            'account_status' => (bool) $user->status,

            'membership' => [
                'id' => $membership->id,
                'status' => $membership->status,
                'revoked_at' => $membership
                    ->revoked_at
                    ?->toISOString(),
            ],

            'roles' => $user->roles
                ->map(fn ($role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'system_key' => $role->system_key,
                    'status' => (bool) $role->status,
                ])
                ->values()
                ->all(),

            'locations' => [
                'plants' => $plants,
                'areas' => $areas,
            ],
        ];
    }

    /**
     * Comprueba que la reactivación no restaure acceso
     * a ubicaciones fuera del alcance del actor.
     */
    private function ensureCanRestoreLocations(
        User $actor,
        User $target
    ): void {
        $organizationId = $this->context->requireId();

        $plants = $target->plants()
            ->wherePivot(
                'organization_id',
                $organizationId
            )
            ->where(
                'plants.organization_id',
                $organizationId
            )
            ->get();

        $areas = $target->areas()
            ->wherePivot(
                'organization_id',
                $organizationId
            )
            ->where(
                'areas.organization_id',
                $organizationId
            )
            ->get();

        foreach ($plants as $plant) {
            $this->security->ensurePlantScope(
                $actor,
                $plant
            );
        }

        foreach ($areas as $area) {
            $this->security->ensureAreaScope(
                $actor,
                $area
            );
        }
    }

    /**
     * Representación de la membresía para auditoría.
     */
    private function membershipSnapshot(
        OrganizationMembership $membership
    ): array {
        return [
            'user_id' => $membership->user_id,
            'organization_id' => $membership->organization_id,
            'status' => $membership->status,
            'revoked_at' => $membership
                ->revoked_at
                ?->toISOString(),
        ];
    }
}
