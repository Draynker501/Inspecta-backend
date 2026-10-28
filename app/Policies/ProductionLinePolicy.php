<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de líneas de producción.
 *
 * Lectura: Administrador de la organización, o usuario con el área de la
 * línea asignada, o con la planta de esa área asignada.
 * Escritura: solo Administrador de la organización.
 */
class ProductionLinePolicy extends TenantPolicy
{
    public function view(User $user, Model $model): bool
    {
        if (! parent::view($user, $model)) {
            return false;
        }

        if ($user->isAdmin()) {
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
}
