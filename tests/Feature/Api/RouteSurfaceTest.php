<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class RouteSurfaceTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /**
     * Superficie de rutas permitida para la API de INSPECTA.
     *
     * Excepción conocida (pendiente de revisión para producción):
     * la URI "/" sirve la vista "welcome" y no es un endpoint de API.
     * Se mantiene por ahora para compatibilidad con el esqueleto de Laravel,
     * pero debe revisarse antes de desplegar a producción.
     */
    public function test_todas_las_rutas_registradas_pertenecen_a_la_superficie_permitida(): void
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        $unexpected = array_values(array_filter(
            $uris,
            static fn (string $uri): bool => ! (
                str_starts_with($uri, 'api/')
                || $uri === 'up'
                || $uri === '/'
            )
        ));

        $this->assertSame(
            [],
            $unexpected,
            'Rutas fuera de la superficie permitida: '.implode(', ', $unexpected)
        );
    }

    public function test_las_seis_operaciones_api_esperadas_estan_registradas(): void
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $operations[] = $method.' '.$route->uri();
                }
            }
        }

        $operations = array_values(array_unique($operations));

        $expected = [
            'POST api/auth/login',
            'POST api/auth/logout',
            'GET api/auth/me',
            'GET api/auth/organizations',
            'GET api/plants',
            'POST api/plants',
        ];

        foreach ($expected as $operation) {
            $this->assertContains($operation, $operations, "Operación esperada ausente: {$operation}");
        }

        // No deben existir operaciones de dominio adicionales.
        $apiOperations = array_values(array_filter(
            $operations,
            static fn (string $op): bool => str_starts_with($op, 'GET api/')
                || str_starts_with($op, 'POST api/')
                || str_starts_with($op, 'PUT api/')
                || str_starts_with($op, 'PATCH api/')
                || str_starts_with($op, 'DELETE api/')
        ));

        $allowed = [
            'POST api/auth/login',
            'POST api/auth/logout',
            'GET api/auth/me',
            'GET api/auth/organizations',
            'GET api/plants',
            'POST api/plants',
        ];

        $unexpected = array_values(array_diff($apiOperations, $allowed));

        $this->assertSame([], $unexpected, 'Operaciones API inesperadas: '.implode(', ', $unexpected));
    }

    public function test_las_cuatro_rutas_autenticadas_requieren_token(): void
    {
        $this->postJson('/api/auth/logout')->assertStatus(401);
        $this->getJson('/api/auth/me')->assertStatus(401);
        $this->getJson('/api/auth/organizations')->assertStatus(401);
        $this->getJson('/api/plants')->assertStatus(401);
    }

    public function test_login_es_publico(): void
    {
        $this->postJson('/api/auth/login', [])->assertStatus(422);
    }

    public function test_plants_declara_el_grupo_api_tenant_en_la_ruta(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'api/plants' && in_array('GET', $route->methods(), true));

        $this->assertNotNull($route);
        $this->assertContains('api.tenant', $route->middleware());
    }

    public function test_plants_requiere_contexto_de_tenant(): void
    {
        $organization = $this->clientOrganization('ORG-ROUTE');
        $user = $this->admin($organization);
        $token = $user->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/plants')
            ->assertStatus(400)
            ->assertJsonPath('code', 'organization_required');

        $this->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/plants')
            ->assertOk();
    }

    public function test_rutas_planeadas_no_estan_registradas(): void
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        $this->assertNotContains('api/areas/{areaId}', $uris);
        $this->assertNotContains('api/users', $uris);
        $this->assertNotContains('api/checklists', $uris);
    }

    public function test_no_estan_registradas_las_rutas_desactivadas(): void
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        $this->assertNotContains('sanctum/csrf-cookie', $uris);

        foreach ($uris as $uri) {
            $this->assertStringStartsNotWith(
                'storage/',
                $uri,
                "Ruta de servido de archivos registrada: {$uri}"
            );
        }
    }
}
