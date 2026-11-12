<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de plantas.
 *
 * Lectura: miembro de la organización con `plants.view`; el alcance se
 * evalúa por asignación (`user_plants`) salvo que posea `locations.view_all`.
 * Escritura: `plants.manage` dentro del tenant activo.
 */
class PlantPolicy extends TenantPolicy
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

        return $user->hasPlantAccess((string) $model->getKey());
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
