<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiErrorResponse;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantErrorCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Resuelve la organización del usuario autenticado y la publica en el
 * TenantContext. Debe ejecutarse después de api.auth.
 *
 * La resolución se basa exclusivamente en la cabecera X-Organization-Id y
 * en una membresía activa del usuario autenticado sobre esa organización.
 * Nunca se utiliza users.organization_id como fuente ni como fallback.
 *
 * Un usuario sin organización válida no obtiene contexto: se responde 403
 * y no se ejecuta ningún controlador de dominio.
 */
class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        $context = app(TenantContext::class);
        $context->clear();

        $user = $request->user();

        if (! $user) {
            return ApiErrorResponse::make(ApiErrorCode::Unauthenticated);
        }

        $organizationId = $request->header('X-Organization-Id');

        if (blank($organizationId)) {
            return ApiErrorResponse::make(TenantErrorCode::ORGANIZATION_REQUIRED);
        }

        if (! Str::isUuid($organizationId)) {
            return ApiErrorResponse::make(TenantErrorCode::ORGANIZATION_INVALID);
        }

        // withTrashed permite detectar y distinguir organizaciones
        // eliminadas lógicamente de las que simplemente no existen.
        $organization = Organization::withTrashed()->find($organizationId);

        // Los 403 de contexto comparten exactamente el mismo texto de
        // mensaje: el motivo solo se distingue por el código (D-31b).
        // organization_not_tenant usa el mismo texto con código propio.
        if (! $organization || $organization->trashed() || ! $organization->status) {
            return ApiErrorResponse::make(TenantErrorCode::ORGANIZATION_ACCESS_DENIED);
        }

        if ($organization->type !== Organization::TYPE_CLIENT) {
            return ApiErrorResponse::make(TenantErrorCode::ORGANIZATION_NOT_TENANT);
        }

        // La membresía se consulta directamente sobre organization_memberships
        // (sin scopes dependientes del contexto todavía no establecido).
        $hasMembership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->exists();

        if (! $hasMembership) {
            return ApiErrorResponse::make(TenantErrorCode::ORGANIZATION_ACCESS_DENIED);
        }

        if (OrganizationMembership::findActive($user->id, $organization->id) === null) {
            return ApiErrorResponse::make(TenantErrorCode::ORGANIZATION_ACCESS_DENIED);
        }

        $context->set($organization->id);

        return $next($request);
    }
}
