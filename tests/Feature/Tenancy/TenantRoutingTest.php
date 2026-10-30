<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guardia de arquitectura: salvo las rutas públicas de autenticación y el
 * logout (que debe seguir disponible sin tenant activo), ningún endpoint
 * bajo /api puede registrarse sin el grupo api.tenant.
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
    ];

    public function test_las_rutas_api_exigen_contexto_organizacional(): void
    {
        $apiRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'));

        $this->assertTrue($apiRoutes->isNotEmpty(), 'No se encontraron rutas de API.');

        foreach ($apiRoutes as $route) {
            if (in_array($route->uri(), self::ROUTES_WITHOUT_TENANT, true)) {
                continue;
            }

            $this->assertContains(
                'api.tenant',
                $route->gatherMiddleware(),
                "La ruta {$route->uri()} no exige contexto organizacional (api.tenant)."
            );
        }
    }

    public function test_las_rutas_publicas_no_exigen_contexto(): void
    {
        foreach (self::ROUTES_WITHOUT_TENANT as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($route) => $route->uri() === $uri);

            $this->assertNotNull($route, "No existe la ruta {$uri}.");
            $this->assertNotContains(
                'api.tenant',
                $route->gatherMiddleware(),
                "La ruta {$uri} no debe resolver tenant."
            );
        }
    }
}
