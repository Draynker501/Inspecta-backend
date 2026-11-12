<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Errores de dominio al revocar membresías (M2.5).
 *
 * No se exponen como endpoints HTTP en M2.5; se usan para validar
 * autorización, idempotencia y atomicidad en el servicio y en pruebas.
 */
final class MembershipRevocationException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('El actor no está autorizado para revocar membresías en esta organización.');
    }

    public static function selfRevocation(): self
    {
        return new self('No se permite revocar la propia membresía del actor.');
    }

    public static function membershipNotFound(): self
    {
        return new self('No existe membresía del usuario en la organización indicada.');
    }
}
