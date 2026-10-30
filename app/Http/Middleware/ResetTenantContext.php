<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Limpia el contexto organizacional antes de cada petición (y al
 * terminar), para que nunca se herede el tenant de otra petición en
 * procesos de larga vida (Octane, workers, pruebas).
 */
class ResetTenantContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        app(TenantContext::class)->clear();

        return $next($request);
    }

    public function terminate(Request $request, mixed $response): void
    {
        app(TenantContext::class)->clear();
    }
}
