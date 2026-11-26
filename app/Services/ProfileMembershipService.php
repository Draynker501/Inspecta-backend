<?php

namespace App\Services;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class ProfileMembershipService
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Obtener la información organizacional del usuario autenticado.
     */
    public function show(User $user): array
    {
        $organizationId = $this->tenantContext->requireId();

        $organization = Organization::query()
            ->whereKey($organizationId)
            ->where('status', true)
            ->where('type', Organization::TYPE_CLIENT)
            ->first();

        $membership = OrganizationMembership::findActive(
            $user->getKey(),
            $organizationId
        );

        abort_unless(
            $organization !== null
            && $membership !== null,
            403
        );

        return [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'code' => $organization->code,
                'type' => $organization->type,
                'status' => (bool) $organization->status,
            ],

            'membership' => [
                'id' => $membership->id,
                'status' => $membership->status,
                'revoked_at' => $membership->revoked_at
                    ?->toISOString(),
            ],

            'roles' => $this->getRoles(
                $user,
                $organizationId
            ),

            'permissions' => $this->getPermissions($user),

            'locations' => $this->getLocations(
                $user,
                $organizationId
            ),
        ];
    }

    /**
     * Obtener los roles asignados dentro de la organización activa.
     *
     * Incluye roles inactivos con fines informativos.
     */
    private function getRoles(
        User $user,
        string $organizationId
    ): array {
        return $user->roles()
            ->wherePivot(
                'organization_id',
                $organizationId
            )
            ->where(
                'roles.organization_id',
                $organizationId
            )
            ->orderBy('roles.name')
            ->orderBy('roles.id')
            ->get([
                'roles.id',
                'roles.name',
                'roles.status',
            ])
            ->map(fn ($role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'status' => (bool) $role->status,
            ])
            ->values()
            ->all();
    }

    /**
     * Obtener exclusivamente los permisos efectivos del usuario.
     */
    private function getPermissions(User $user): array
    {
        return collect(Permission::cases())
            ->filter(
                fn (Permission $permission): bool => $user->hasPermission($permission)
            )
            ->map(
                fn (Permission $permission): string => $permission->value
            )
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Obtener el alcance y las ubicaciones asignadas directamente.
     */
    private function getLocations(
        User $user,
        string $organizationId
    ): array {
        $canViewLocations = $user->hasPermission(
            Permission::PlantsView
        );

        $canViewAllLocations =
            $canViewLocations
            && $user->hasPermission(
                Permission::LocationsViewAll
            );

        if (! $canViewLocations) {
            return [
                'can_view' => false,
                'view_all' => false,
                'plants' => [],
                'areas' => [],
            ];
        }

        $plants = $user->plants()
            ->wherePivot(
                'organization_id',
                $organizationId
            )
            ->where(
                'plants.organization_id',
                $organizationId
            )
            ->orderBy('plants.name')
            ->orderBy('plants.id')
            ->get([
                'plants.id',
                'plants.name',
                'plants.code',
                'plants.status',
            ])
            ->map(fn ($plant): array => [
                'id' => $plant->id,
                'name' => $plant->name,
                'code' => $plant->code,
                'status' => (bool) $plant->status,
                'available' => (bool) $plant->status,
            ])
            ->values()
            ->all();

        $areas = $user->areas()
            ->wherePivot(
                'organization_id',
                $organizationId
            )
            ->where(
                'areas.organization_id',
                $organizationId
            )
            ->join(
                'plants',
                function ($join) {
                    $join->on(
                        'plants.id',
                        '=',
                        'areas.plant_id'
                    )->on(
                        'plants.organization_id',
                        '=',
                        'areas.organization_id'
                    );
                }
            )
            ->whereNull('plants.deleted_at')
            ->orderBy('plants.name')
            ->orderBy('areas.name')
            ->orderBy('areas.id')
            ->get([
                'areas.id',
                'areas.plant_id',
                'areas.name',
                'areas.code',
                'areas.status',
                'plants.name as plant_name',
                'plants.status as plant_status',
            ])
            ->map(fn ($area): array => [
                'id' => $area->id,
                'plant_id' => $area->plant_id,
                'plant_name' => $area->plant_name,
                'name' => $area->name,
                'code' => $area->code,
                'status' => (bool) $area->status,
                'available' => (bool) $area->status
                    && (bool) $area->plant_status,
            ])
            ->values()
            ->all();

        return [
            'can_view' => $canViewLocations,
            'view_all' => $canViewAllLocations,
            'plants' => $plants,
            'areas' => $areas,
        ];
    }
}
