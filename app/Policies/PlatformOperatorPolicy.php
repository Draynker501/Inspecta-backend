<?php

namespace App\Policies;

use App\Models\User;

/**
 * Puerta de operaciones de plataforma (multiempresa).
 *
 * No hereda de TenantPolicy y no concede acceso a datos de dominio.
 * Stub inicial para P3.3.3 (pruebas en rojo): la lógica se implementa
 * en el paso siguiente.
 */
class PlatformOperatorPolicy
{
    public function viewMemberships(User $user): bool
    {
        return $user->isPlatformOperator()
            && $user->hasPlatformRole();
    }

    public function manageMemberships(User $user): bool
    {
        return $user->isPlatformOperator() && $user->hasPlatformRole();
    }
}
