<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Resuelve la organización del usuario autenticado y la publica en el
 * TenantContext. Debe ejecutarse después de api.auth.
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

        if (blank($user->organization_id)) {
            return response()->json([
                'message' => 'El usuario no tiene una organización asignada.',
            ], 403);
        }

        $organization = Organization::withTrashed()->find($user->organization_id);

        if (! $organization) {
            return response()->json([
                'message' => 'La organización del usuario no existe.',
            ], 403);
        }

        if ($organization->trashed() || ! $organization->status) {
            return response()->json([
                'message' => 'La organización está inactiva. Contacte al administrador.',
            ], 403);
        }

        $context->set($organization->id);

        return $next($request);
    }
}
