<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de líneas de producción.
 *
 * Lectura: miembro de la organización con `plants.view`; el alcance se
 * evalúa por asignación del área o de la planta padre salvo que posea
 * `locations.view_all`.
 * Escritura: `plants.manage` dentro del tenant activo.
 */
class ProductionLinePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::PlantsView);
    }

    public function view(User $user, Model $model): bool
    {
        if (! parent::view($user, $model)) {
            return false;
        }

        if (! $this->hasPermission($user, Permission::PlantsView)) {
            return false;
        }

        if ($this->hasPermission($user, Permission::LocationsViewAll)) {
            return true;
        }

        $areaId = $model->getAttribute('area_id');

        if ($areaId === null) {
            return false;
        }

        if ($user->hasAreaAccess((string) $areaId)) {
            return true;
        }

        $plantId = $model->area?->getAttribute('plant_id');

        return $plantId !== null && $user->hasPlantAccess((string) $plantId);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantContext($user)
            && $this->hasPermission($user, Permission::PlantsManage);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->ownsTenant($user, $model)
            && $this->hasPermission($user, Permission::PlantsManage);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }
}
