<?php

namespace App\Support\Api;

use App\Support\Tenancy\TenantErrorCode;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Constructor único de respuestas de error de la API.
 *
 * Produce el sobre uniforme: { message, code, errors? }. El catálogo
 * central define, para cada código, el estado HTTP y el mensaje: el
 * mismo código produce siempre el mismo mensaje (regla D-31b).
 *
 * Dos códigos tienen mensaje dinámico por diseño y exigen uno explícito:
 * - validation_failed: resumen del framework de validación (se conservan
 *   el mensaje y el campo errors del 422 existente).
 * - cross_tenant_operation: mensaje de la excepción concreta.
 */
final class ApiErrorResponse
{
    /**
     * Catálogo central: código => [estado HTTP, mensaje].
     *
     * @var array<string, array{int, ?string}>
     */
    private const CATALOG = [
        ApiErrorCode::Unauthenticated->value => [401, 'No autenticado. Token de acceso ausente o inválido.'],
        ApiErrorCode::InvalidCredentials->value => [401, 'Las credenciales proporcionadas son incorrectas.'],
        ApiErrorCode::UserInactive->value => [403, 'El usuario está inactivo. Contacte al administrador.'],
        ApiErrorCode::ValidationFailed->value => [422, null],
        ApiErrorCode::TooManyAttempts->value => [429, 'Demasiados intentos. Inténtalo de nuevo más tarde.'],
        ApiErrorCode::NotFound->value => [404, 'Recurso no encontrado.'],
        ApiErrorCode::CrossTenantOperation->value => [422, null],
        TenantErrorCode::ORGANIZATION_REQUIRED => [400, 'Cabecera X-Organization-Id requerida.'],
        TenantErrorCode::ORGANIZATION_INVALID => [400, 'El identificador de organización no es un UUID válido.'],
        TenantErrorCode::ORGANIZATION_ACCESS_DENIED => [403, 'No tienes acceso a la organización indicada.'],
        TenantErrorCode::ORGANIZATION_NOT_TENANT => [403, 'No tienes acceso a la organización indicada.'],
    ];

    /**
     * Construye la respuesta de error del sobre uniforme.
     *
     * @param  ApiErrorCode|string  $code  Código del catálogo (ApiErrorCode o constante de TenantErrorCode).
     * @param  string|null  $message  Mensaje explícito (obligatorio para los códigos de mensaje dinámico).
     * @param  array<string, mixed>|null  $errors  Campo errors (solo validation_failed).
     * @param  array<string, mixed>  $headers  Cabeceras adicionales (por ejemplo Retry-After).
     */
    public static function make(
        ApiErrorCode|string $code,
        ?string $message = null,
        ?array $errors = null,
        array $headers = [],
    ): JsonResponse {
        $key = $code instanceof ApiErrorCode ? $code->value : $code;

        if (! array_key_exists($key, self::CATALOG)) {
            throw new InvalidArgumentException("Código de error desconocido: '{$key}'.");
        }

        [$status, $defaultMessage] = self::CATALOG[$key];

        $message ??= $defaultMessage;

        if ($message === null) {
            throw new InvalidArgumentException("El código '{$key}' exige un mensaje explícito.");
        }

        $payload = [
            'message' => $message,
            'code' => $key,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status)->withHeaders($headers);
    }

    /**
     * Códigos registrados en el catálogo central.
     *
     * @return list<string>
     */
    public static function catalogCodes(): array
    {
        return array_keys(self::CATALOG);
    }
}
