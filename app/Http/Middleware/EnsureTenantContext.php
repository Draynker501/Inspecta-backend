<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiErrorResponse;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantErrorCode;
use Closure;
use Illuminate\Http\Request;

/**
 * Exige que exista un contexto organizacional activo (fail-closed).
 *
 * Se aplica automáticamente a todo controlador que extienda
 * TenantController mediante HasMiddleware, de modo que aun cuando una
 * ruta se declare fuera del grupo api.tenant el acceso denegado sea
 * consistente y nunca se filtre información de la excepción.
 */
class EnsureTenantContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (app(TenantContext::class)->id() === null) {
            return ApiErrorResponse::make(TenantErrorCode::ORGANIZATION_REQUIRED);
        }

        return $next($request);
    }
}
