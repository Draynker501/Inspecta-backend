<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza al intentar guardar un modelo con organization_id sin contexto
 * organizacional y sin un valor explícito. Es un fallo de programación:
 * la ruta o el job deben resolver el tenant antes de escribir.
 */
final class MissingTenantContextException extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(
            "No hay contexto organizacional ni organization_id explícito al guardar {$model}."
        );
    }
}
