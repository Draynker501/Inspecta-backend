<?php

namespace App\Support\Bootstrap;

use App\Models\User;
use InvalidArgumentException;

/**
 * Resuelve y valida al actor administrativo de auditoria.
 *
 * Acepta un correo, nunca un identificador arbitrario: un llamador directo no
 * puede atribuir acciones a un usuario sin rol de plataforma.
 */
final class AdministrativeActor
{
    /**
     * Actor opcional: null si no se proporciona; cualquier valor debe ser un
     * administrador de plataforma activo.
     */
    public function optional(mixed $email): ?string
    {
        $normalized = BootstrapInput::email($email, false, 'El email del actor');

        return $normalized === null ? null : $this->resolve($normalized);
    }

    /**
     * Actor obligatorio (bootstrap de clientes).
     */
    public function required(mixed $email): string
    {
        $normalized = BootstrapInput::email($email, true, 'El email del actor');

        return $this->resolve((string) $normalized);
    }

    private function resolve(string $email): string
    {
        $actor = User::query()->where('email', $email)->first();

        if ($actor === null || ! $actor->status || ! $actor->hasPlatformRole()) {
            throw new InvalidArgumentException('El actor debe ser un administrador de plataforma activo.');
        }

        return $actor->id;
    }
}
