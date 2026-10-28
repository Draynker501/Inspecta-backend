<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de áreas.
 *
 * Lectura: Administrador de la organización, o usuario con el área
 * asignada, o con la planta padre asignada.
 * Escritura: solo Administrador de la organización.
 */
class AreaPolicy extends TenantPolicy
{
    public function view(User $user, Model $model): bool
    {
        if (! parent::view($user, $model)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->hasAreaAccess((string) $model->getKey())) {
            return true;
        }

        $plantId = $model->getAttribute('plant_id');

        return $plantId !== null && $user->hasPlantAccess((string) $plantId);
    }
}
