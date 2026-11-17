<?php

namespace Tests\Feature\Incidents;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class IncidentTest extends TestCase
{
    use RefreshDatabase;
    use IncidentFixtures;

    public function test_puede_crear_incidente(): void
    {
        $env = $this->prepareIncidentEnvironment();

        $id = $this->createIncidentForTests($env);

        $this->assertDatabaseHas('incidents', [
            'id' => $id,
            'organization_id' => $env['organization']->id,
            'status' => 'open',
            'risk_level' => 'high',
            'reported_by' => $env['user']->id,
        ]);
    }

    public function test_puede_consultar_incidente(): void
    {
        $env = $this->prepareIncidentEnvironment();
        $id = $this->createIncidentForTests($env);

        $this->getJson("/api/incidents/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath(
                'data.title',
                'Extintor en mal estado'
            );
    }

    public function test_puede_listar_incidentes(): void
    {
        $env = $this->prepareIncidentEnvironment();
        $id = $this->createIncidentForTests($env);

        $this->getJson('/api/incidents?page=1&per_page=10')
            ->assertOk()
            ->assertJsonFragment(['id' => $id])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_puede_actualizar_estado(): void
    {
        $env = $this->prepareIncidentEnvironment();
        $id = $this->createIncidentForTests($env);

        $this->patchJson(
            "/api/incidents/{$id}/status",
            ['status' => 'in_progress']
        )->assertOk()
         ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('incidents', [
            'id' => $id,
            'status' => 'in_progress',
        ]);
    }

    public function test_rechaza_nivel_de_riesgo_invalido(): void
    {
        $env = $this->prepareIncidentEnvironment();

        $this->postJson('/api/incidents', [
            'plant_id' => $env['plant']->id,
            'area_id' => $env['area']->id,
            'title' => 'Incidente de prueba',
            'description' => 'Descripción de prueba',
            'risk_level' => 'extreme',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['risk_level']);
    }

    public function test_rechaza_estado_invalido(): void
    {
        $env = $this->prepareIncidentEnvironment();
        $id = $this->createIncidentForTests($env);

        $this->patchJson(
            "/api/incidents/{$id}/status",
            ['status' => 'cancelled']
        )->assertUnprocessable()
         ->assertJsonValidationErrors(['status']);
    }

    public function test_no_permite_consultar_incidente_ajeno(): void
    {
        $orgB = $this->prepareIncidentEnvironment('INC-B');
        $incidentB = $this->createIncidentForTests($orgB);

        app(TenantContext::class)->clear();

        $orgA = $this->prepareIncidentEnvironment('INC-A');

        $this->getJson("/api/incidents/{$incidentB}")
            ->assertNotFound();

        $this->patchJson(
            "/api/incidents/{$incidentB}/status",
            ['status' => 'resolved']
        )->assertNotFound();

        $this->assertDatabaseHas('incidents', [
            'id' => $incidentB,
            'organization_id' => $orgB['organization']->id,
            'status' => 'open',
        ]);
    }
}