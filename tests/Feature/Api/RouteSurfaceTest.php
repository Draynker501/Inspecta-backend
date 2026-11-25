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

    public function test_las_operaciones_api_esperadas_estan_registradas(): void
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
            'GET api/dashboard',
            'GET api/plants',
            'POST api/plants',
            'GET api/plants/{plantId}',
            'GET api/plants/{plantId}/areas',
            'POST api/plants/{plantId}/areas',
            'GET api/areas/{areaId}',
            'GET api/areas/{areaId}/lines',
            'POST api/areas/{areaId}/lines',
            'GET api/production-lines/{productionLineId}',
            'GET api/locations/hierarchy',
            'GET api/norms',
            'GET api/norms/{normId}',
            'GET api/norms/{normId}/requirements',
            'GET api/requirements/{requirementId}',
            'GET api/checklists',
            'POST api/checklists',
            'GET api/checklists/{checklistId}',
            'GET api/checklists/{checklistId}/versions',
            'POST api/checklists/{checklistId}/versions',
            'GET api/versions/{versionId}/sections',
            'POST api/versions/{versionId}/sections',
            'PUT api/checklists/{checklistId}',
            'PATCH api/checklists/{checklistId}',
            'GET api/checklists/{checklistId}/active-version',
            'GET api/checklists/{checklistId}/versions/{versionId}',
            'PUT api/checklists/{checklistId}/versions/{versionId}',
            'PATCH api/checklists/{checklistId}/versions/{versionId}',
            'PUT api/sections/{sectionId}',
            'GET api/sections/{sectionId}',
            'PUT api/questions/{questionId}',
            'GET api/questions/{questionId}',
            'POST api/norms',
            'PUT api/norms/{normId}',
            'PATCH api/norms/{normId}',
            'POST api/norms/{normId}/requirements',
            'PUT api/requirements/{requirementId}',
            'GET api/questions/{questionId}/norm-requirements',
            'POST api/questions/{questionId}/norm-requirements',
            'DELETE api/questions/{questionId}/norm-requirements/{linkId}',
            'GET api/sections/{sectionId}/questions',
            'POST api/sections/{sectionId}/questions',
            'GET api/inspections',
            'POST api/inspections',
            'GET api/inspections/{inspectionId}',
            'POST api/inspections/{inspectionId}/complete',
            'PUT api/inspections/{inspectionId}/status',
            'GET api/inspections/{inspectionId}/checklist',
            'GET api/inspections/{inspectionId}/answers',
            'PUT api/inspections/{inspectionId}/answers/{questionId}',
            'DELETE api/inspections/{inspectionId}/answers/{questionId}',
            'PATCH api/questions/{questionId}',
            'GET api/incidents',
            'POST api/incidents',
            'GET api/incidents/{incidentId}',
            'PATCH api/incidents/{incidentId}/status',
            'GET api/corrective-actions',
            'POST api/corrective-actions',
            'GET api/corrective-actions/{actionId}',
            'PATCH api/corrective-actions/{actionId}',
            'GET api/corrective-actions/{actionId}/updates',
            'POST api/corrective-actions/{actionId}/updates',
            'GET api/evidences',
            'POST api/evidences',
            'GET api/evidences/{evidenceId}',
            'GET api/evidences/{evidenceId}/download',
            'GET api/evidences/{evidenceId}/preview',
            'PATCH api/versions/{versionId}/sections/reorder',
            'PATCH api/sections/{sectionId}/questions/reorder',
            'GET api/reports/summary',
            'GET api/users',
            'POST api/users',
            'GET api/users/{userId}',
            'PATCH api/users/{userId}/membership/status',
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
            'GET api/auth/capabilities',
            'GET api/auth/organizations',
            'GET api/dashboard',
            'GET api/plants',
            'POST api/plants',
            'GET api/plants/{plantId}',
            'GET api/plants/{plantId}/areas',
            'POST api/plants/{plantId}/areas',
            'GET api/areas/{areaId}',
            'GET api/areas/{areaId}/lines',
            'POST api/areas/{areaId}/lines',
            'GET api/production-lines/{productionLineId}',
            'GET api/locations/hierarchy',
            'GET api/norms',
            'GET api/norms/{normId}',
            'GET api/norms/{normId}/requirements',
            'GET api/requirements/{requirementId}',
            'GET api/checklists',
            'POST api/checklists',
            'GET api/checklists/{checklistId}',
            'GET api/checklists/{checklistId}/versions',
            'POST api/checklists/{checklistId}/versions',
            'GET api/versions/{versionId}/sections',
            'POST api/versions/{versionId}/sections',
            'PUT api/checklists/{checklistId}',
            'PATCH api/checklists/{checklistId}',
            'GET api/checklists/{checklistId}/active-version',
            'GET api/checklists/{checklistId}/versions/{versionId}',
            'PUT api/checklists/{checklistId}/versions/{versionId}',
            'PATCH api/checklists/{checklistId}/versions/{versionId}',
            'PUT api/sections/{sectionId}',
            'GET api/sections/{sectionId}',
            'PUT api/questions/{questionId}',
            'GET api/questions/{questionId}',
            'POST api/norms',
            'PUT api/norms/{normId}',
            'PATCH api/norms/{normId}',
            'POST api/norms/{normId}/requirements',
            'PUT api/requirements/{requirementId}',
            'GET api/questions/{questionId}/norm-requirements',
            'POST api/questions/{questionId}/norm-requirements',
            'DELETE api/questions/{questionId}/norm-requirements/{linkId}',
            'GET api/sections/{sectionId}/questions',
            'POST api/sections/{sectionId}/questions',
            'GET api/inspections',
            'POST api/inspections',
            'GET api/inspections/{inspectionId}',
            'POST api/inspections/{inspectionId}/complete',
            'PUT api/inspections/{inspectionId}/status',
            'GET api/inspections/{inspectionId}/checklist',
            'GET api/inspections/{inspectionId}/answers',
            'PUT api/inspections/{inspectionId}/answers/{questionId}',
            'DELETE api/inspections/{inspectionId}/answers/{questionId}',
            'PATCH api/questions/{questionId}',
            'GET api/incidents',
            'POST api/incidents',
            'GET api/incidents/{incidentId}',
            'PATCH api/incidents/{incidentId}/status',
            'GET api/corrective-actions',
            'POST api/corrective-actions',
            'GET api/corrective-actions/{actionId}',
            'PATCH api/corrective-actions/{actionId}',
            'GET api/corrective-actions/{actionId}/updates',
            'POST api/corrective-actions/{actionId}/updates',
            'GET api/evidences',
            'POST api/evidences',
            'GET api/evidences/{evidenceId}',
            'GET api/evidences/{evidenceId}/download',
            'GET api/evidences/{evidenceId}/preview',
            'PATCH api/versions/{versionId}/sections/reorder',
            'PATCH api/sections/{sectionId}/questions/reorder',
            'GET api/reports/summary',
            'GET api/users',
            'POST api/users',
            'GET api/users/{userId}',
            'PATCH api/users/{userId}/membership/status',
        ];

        $unexpected = array_values(array_diff($apiOperations, $allowed));

        $this->assertSame([], $unexpected, 'Operaciones API inesperadas: '.implode(', ', $unexpected));
    }

    public function test_las_rutas_autenticadas_incluida_el_alta_de_areas_requieren_token(): void
    {
        $this->postJson('/api/auth/logout')->assertStatus(401);
        $this->getJson('/api/auth/me')->assertStatus(401);
        $this->getJson('/api/auth/organizations')->assertStatus(401);
        $this->getJson('/api/plants')->assertStatus(401);
        $this->postJson('/api/plants/'.str_repeat('0', 36).'/areas', [])->assertStatus(401);
        $this->getJson('/api/dashboard')->assertStatus(401);
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

    public function test_alta_de_areas_declara_api_tenant_y_requiere_contexto(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'api/plants/{plantId}/areas' && in_array('POST', $route->methods(), true));

        $this->assertNotNull($route);
        $this->assertContains('api.tenant', $route->middleware());

        $organization = $this->clientOrganization('ORG-AREA-ROUTE');
        $user = $this->admin($organization);
        $token = $user->createToken('tests')->plainTextToken;
        $plant = $this->plant($organization, 'P');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/plants/{$plant->id}/areas", ['name' => 'A', 'code' => 'A'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'organization_required');
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

    public function test_rutas_administrativas_planeadas_no_estan_registradas(): void
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        $this->assertNotContains('api/roles', $uris);

        $this->assertNotContains('api/permissions', $uris);

        $this->assertNotContains(
            'api/users/{userId}/roles',
            $uris
        );

        $this->assertNotContains(
            'api/users/{userId}/locations',
            $uris
        );
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

    public function test_dashboard_declara_el_grupo_api_tenant_en_la_ruta(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route) => $route->uri() === 'api/dashboard'
                && in_array('GET', $route->methods(), true)
        );

        $this->assertNotNull($route);

        $this->assertContains(
            'api.tenant',
            $route->middleware()
        );
    }

    public function test_users_declara_el_grupo_api_tenant_en_sus_rutas(): void
    {
        $operations = [
            ['GET', 'api/users'],
            ['POST', 'api/users'],
            ['GET', 'api/users/{userId}'],
            ['PATCH', 'api/users/{userId}/membership/status'],
        ];

        foreach ($operations as [$method, $uri]) {
            $route = collect(Route::getRoutes())->first(
                fn ($route) => $route->uri() === $uri
                    && in_array($method, $route->methods(), true)
            );

            $this->assertNotNull(
                $route,
                "Ruta no registrada: {$method} {$uri}"
            );

            $this->assertContains(
                'api.tenant',
                $route->middleware()
            );
        }
    }
}
