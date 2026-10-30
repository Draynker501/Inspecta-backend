<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Se lanza cuando una operación intenta leer o escribir datos de otra
 * organización. En HTTP se traduce en 422 para no confirmar la existencia
 * del recurso ajeno.
 */
final class CrossTenantOperationException extends RuntimeException
{
    public static function write(): self
    {
        return new self('La operación intenta escribir datos de otra organización.');
    }

    public static function relation(string $foreignKey): self
    {
        return new self("El campo '{$foreignKey}' referencia un recurso inexistente o de otra organización.");
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
        ], 422);
    }
}
