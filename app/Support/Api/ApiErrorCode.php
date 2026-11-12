<?php

namespace App\Support\Api;

/**
 * Códigos de error de la API fuera del contexto de tenant (D-31c).
 *
 * Catálogo aprobado:
 * - unauthenticated: 401, token ausente o inválido.
 * - invalid_credentials: 401, login con credenciales erróneas.
 * - user_inactive: 403, login y token de usuario inactivo.
 * - validation_failed: 422, errores de validación.
 * - too_many_attempts: 429, límite de intentos de login.
 * - not_found: 404, recurso inexistente.
 * - cross_tenant_operation: 422, CrossTenantOperationException.
 *
 * TenantErrorCode (códigos de contexto de tenant) se conserva sin
 * cambios: los mensajes y estados HTTP de ambos catálogos se resuelven
 * en ApiErrorResponse para garantizar que el mismo código produce
 * siempre el mismo mensaje (regla D-31b).
 */
enum ApiErrorCode: string
{
    /**
     * Mensaje fijo y unificado para los 403 de autorización (D-39).
     */
    public const FORBIDDEN_MESSAGE = 'No tienes permiso para realizar esta acción.';

    case Unauthenticated = 'unauthenticated';

    case InvalidCredentials = 'invalid_credentials';

    case UserInactive = 'user_inactive';

    case ValidationFailed = 'validation_failed';

    case TooManyAttempts = 'too_many_attempts';

    case NotFound = 'not_found';

    case CrossTenantOperation = 'cross_tenant_operation';

    case Forbidden = 'forbidden';
}
