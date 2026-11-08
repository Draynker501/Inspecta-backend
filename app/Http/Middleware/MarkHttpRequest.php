<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Marca el inicio de una petición HTTP en el TenantContext.
 *
 * Se registra como primer middleware global. El flag se reinicia al
 * inicio (handle) y al final (terminate) de cada petición, de modo que
 * no persista entre peticiones en procesos de larga vida.
 */
class MarkHttpRequest
{
    public function handle(Request $request, Closure $next): mixed
    {
        app(TenantContext::class)->markHttpRequest();

        try {
            return $next($request);
        } finally {
            app(TenantContext::class)->clearHttpRequest();
        }
    }

    public function terminate(Request $request, mixed $response): void
    {
        app(TenantContext::class)->clearHttpRequest();
    }
}
