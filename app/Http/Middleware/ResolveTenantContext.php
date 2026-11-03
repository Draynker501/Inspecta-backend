<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Support\Tenancy\TenantContext;
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
            return response()->json([
                'message' => 'No autenticado. Token de acceso ausente.',
            ], 401);
        }

        $organizationId = $request->header('X-Organization-Id');

        if (blank($organizationId)) {
            return response()->json([
                'message' => 'Cabecera X-Organization-Id requerida.',
            ], 400);
        }

        if (! Str::isUuid($organizationId)) {
            return response()->json([
                'message' => 'El identificador de organización no es un UUID válido.',
            ], 400);
        }

        // withTrashed permite detectar y distinguir organizaciones
        // eliminadas lógicamente de las que simplemente no existen.
        $organization = Organization::withTrashed()->find($organizationId);

        if (! $organization) {
            return response()->json([
                'message' => 'La organización no existe o no está autorizada.',
            ], 403);
        }

        if ($organization->trashed()) {
            return response()->json([
                'message' => 'La organización ha sido eliminada.',
            ], 403);
        }

        if (! $organization->status) {
            return response()->json([
                'message' => 'La organización está inactiva. Contacte al administrador.',
            ], 403);
        }

        if ($organization->type !== Organization::TYPE_CLIENT) {
            return response()->json([
                'message' => 'Tipo de organización no permitido.',
            ], 403);
        }

        // La membresía se consulta directamente sobre organization_memberships
        // (sin scopes dependientes del contexto todavía no establecido).
        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->first();

        if (! $membership) {
            return response()->json([
                'message' => 'No tiene membresía en esta organización.',
            ], 403);
        }

        if (! $membership->isActive()) {
            return response()->json([
                'message' => 'La membresía no está activa.',
            ], 403);
        }

        $context->set($organization->id);

        return $next($request);
    }
}
