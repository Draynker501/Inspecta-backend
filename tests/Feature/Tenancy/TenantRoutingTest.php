<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guardia de arquitectura multi-tenancy.
 *
 * Las operaciones de autenticación e identidad global pueden
 * ejecutarse sin contexto organizacional.
 *
 * Las operaciones de dominio y de consulta de membresías
 * deben declarar el grupo api.tenant.
 */
class TenantRoutingTest extends TestCase
{
    /**
     * Rutas que legítimamente no exigen contexto organizacional.
     *
     * @var list<string>
     */
    private const ROUTES_WITHOUT_TENANT = [
        'api/auth/login',
        'api/auth/logout',
        'api/auth/me',
        'api/auth/password',
        'api/auth/organizations',
    ];

    public function test_las_rutas_api_exigen_contexto_organizacional(): void
    {
        $apiRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(
                fn ($route) => str_starts_with(
                    $route->uri(),
                    'api/'
                )
            );

        $this->assertTrue(
            $apiRoutes->isNotEmpty(),
            'No se encontraron rutas de API.'
        );

        foreach ($apiRoutes as $route) {
            if (in_array(
                $route->uri(),
                self::ROUTES_WITHOUT_TENANT,
                true
            )) {
                continue;
            }

            $this->assertContains(
                'api.tenant',
                $route->gatherMiddleware(),
                "La ruta {$route->uri()} no exige contexto organizacional (api.tenant)."
            );
        }
    }

    public function test_las_rutas_globales_no_exigen_contexto(): void
    {
        foreach (self::ROUTES_WITHOUT_TENANT as $uri) {
            $routes = collect(Route::getRoutes()->getRoutes())
                ->filter(
                    fn ($route) => $route->uri() === $uri
                );

            $this->assertTrue(
                $routes->isNotEmpty(),
                "No existe la ruta {$uri}."
            );

            foreach ($routes as $route) {
                $middleware = $route->gatherMiddleware();

                $this->assertNotContains(
                    'api.tenant',
                    $middleware,
                    "La ruta {$uri} no debe resolver tenant."
                );

                $this->assertNotContains(
                    'tenant.require',
                    $middleware,
                    "La ruta {$uri} no debe exigir tenant."
                );

                if ($uri !== 'api/auth/login') {
                    $this->assertContains(
                        'api.auth',
                        $middleware,
                        "La ruta {$uri} debe exigir autenticación."
                    );
                }
            }
        }
    }
}
