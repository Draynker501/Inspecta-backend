<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\Inspection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class InspectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->activeClient()
            && ($user->hasPermission(Permission::InspectionsExecute)
                || $user->hasPermission(Permission::InspectionsManage));
    }

    public function create(User $user): bool
    {
        return $this->activeClient()
            && $user->hasPermission(Permission::InspectionsExecute);
    }

    public function view(User $user, Inspection $inspection): bool
    {
        $organizationId = app(TenantContext::class)->id();

        if (! $this->viewAny($user) || $inspection->organization_id !== $organizationId) {
            return false;
        }

        if ($inspection->user_id !== $user->id
            && ! $user->hasPermission(Permission::InspectionsManage)) {
            return false;
        }

        return $this->hasLocationAccess($user, $inspection->plant_id, $inspection->area_id);
    }

    /**
     * Lectura de respuestas: mismas reglas que el detalle de la inspección.
     */
    public function viewAnswers(User $user, Inspection $inspection): bool
    {
        return $this->view($user, $inspection);
    }

    /**
     * Escritura de respuestas: propietario, permiso de ejecución, membresía y
     * asignación vigentes. `inspections.manage` no concede escritura.
     *
     * El estado `draft` se valida en el controlador para distinguir una
     * restricción de estado (422) de una denegación de autorización (403).
     */
    public function answer(User $user, Inspection $inspection): bool
    {
        $organizationId = app(TenantContext::class)->id();

        if (! $this->activeClient() || $inspection->organization_id !== $organizationId) {
            return false;
        }

        if ($inspection->user_id !== $user->id
            || ! $user->hasPermission(Permission::InspectionsExecute)) {
            return false;
        }

        return $this->hasLocationAccess($user, $inspection->plant_id, $inspection->area_id);
    }

    public function review(User $user, Inspection $inspection): bool
    {
        $organizationId = app(TenantContext::class)->id();

        return $this->activeClient()
            && $inspection->organization_id === $organizationId
            && (bool) $user->status
            && $inspection->user_id !== $user->id
            && $user->hasPermission(Permission::InspectionsManage)
            && $this->hasLocationAccess($user, $inspection->plant_id, $inspection->area_id);
    }

    public function hasLocationAccess(User $user, string $plantId, string $areaId): bool
    {
        $organizationId = app(TenantContext::class)->id();

        if (! $this->activeClient()
            || OrganizationMembership::findActive($user->id, $organizationId) === null) {
            return false;
        }

        return DB::table('user_plants')
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('plant_id', $plantId)
            ->exists()
            || DB::table('user_areas')
                ->where('organization_id', $organizationId)
                ->where('user_id', $user->id)
                ->where('area_id', $areaId)
                ->exists();
    }

    private function activeClient(): bool
    {
        $organizationId = app(TenantContext::class)->id();

        return $organizationId !== null
            && Organization::query()
                ->whereKey($organizationId)
                ->where('type', Organization::TYPE_CLIENT)
                ->where('status', true)
                ->exists();
    }
}
