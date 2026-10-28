<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de plantas.
 *
 * Lectura: cualquier miembro de la organización; el índice debe filtrarse
 * por user_plants para roles de campo (responsabilidad del controlador).
 * Escritura: solo Administrador de la organización.
 */
class PlantPolicy extends TenantPolicy
{
    public function view(User $user, Model $model): bool
    {
        if (! parent::view($user, $model)) {
            return false;
        }

        return $user->isAdmin() || $user->hasPlantAccess((string) $model->getKey());
    }
}
