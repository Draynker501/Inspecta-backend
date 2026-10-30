<?php

use App\Http\Middleware\EnsureApiAuthenticated;
use App\Http\Middleware\ResetTenantContext;
use App\Http\Middleware\ResolveTenantContext;
use App\Providers\ExceptionServiceProvider;
use App\Providers\TenancyServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        ExceptionServiceProvider::class,
        TenancyServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // El tenant se limpia en cada petición y al terminar, incluso en
        // procesos de larga vida (Octane) y en pruebas con múltiples llamadas.
        $middleware->prepend(ResetTenantContext::class);

        $middleware->alias([
            'api.auth' => EnsureApiAuthenticated::class,
            'tenant' => ResolveTenantContext::class,
        ]);

        // Autenticación + contexto organizacional + route model binding.
        // Todo endpoint de dominio debe usar este grupo: el binding se
        // resuelve después del tenant, de modo que un ID ajeno produce 404.
        $middleware->group('api.tenant', [
            EnsureApiAuthenticated::class,
            ResolveTenantContext::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
