<?php

namespace Tests\Feature\Incidents;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class CorrectiveActionTest extends TestCase
{
    use RefreshDatabase;
    use IncidentFixtures;

    public function test_puede_crear_accion_correctiva(): void
    {
        $env = $this->prepareIncidentEnvironment();
        $incidentId = $this->createIncidentForTests($env);

        $actionId = $this->createCorrectiveActionForTests(
            $incidentId,
            $env['user']
        );

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $actionId,
            'organization_id' => $env['organization']->id,
            'incident_id' => $incidentId,
            'assigned_to' => $env['user']->id,
            'status' => 'pending',
            'priority' => 'high',
            'completed_at' => null,
        ]);
    }

    public function test_puede_consultar_accion(): void
    {
        $env = $this->prepareIncidentEnvironment();
        $incidentId = $this->createIncidentForTests($env);
        $actionId = $this->createCorrectiveActionForTests(
            $incidentId,
            $env['user']
        );

        $this->getJson("/api/corrective-actions/{$actionId}")
            ->assertOk()
            ->assertJsonPath('data.id', $actionId)
            ->assertJsonPath('data.updates', []);
    }

    public function test_puede_filtrar_por_incidente(): void
    {
        $env = $this->prepareIncidentEnvironment();
        $incidentId = $this->createIncidentForTests($env);
        $actionId = $this->createCorrectiveActionForTests(
            $incidentId,
            $env['user']
        );

        $this->getJson(
            "/api/corrective-actions?incident_id={$incidentId}"
        )->assertOk()
         ->assertJsonPath('meta.total', 1)
         ->assertJsonFragment(['id' => $actionId]);
    }

    public function test_puede_modificar_prioridad_y_descripcion(): void
    {
        $env = $this->prepareIncidentEnvironment();
        $incidentId = $this->createIncidentForTests($env);
        $actionId = $this->createCorrectiveActionForTests(
            $incidentId,
            $env['user']
        );

        $this->patchJson(
            "/api/corrective-actions/{$actionId}",
            [
                'priority' => 'critical',
                'description' => 'Sustituir inmediatamente.',
            ]
        )->assertOk()
         ->assertJsonPath('data.priority', 'critical');

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $actionId,
            'priority' => 'critical',
            'description' => 'Sustituir inmediatamente.',
        ]);
    }

    public function test_rechaza_prioridad_invalida(): void
    {
        $env = $this->prepareIncidentEnvironment();
        $incidentId = $this->createIncidentForTests($env);

        $this->postJson('/api/corrective-actions', [
            'incident_id' => $incidentId,
            'assigned_to' => $env['user']->id,
            'description' => 'Acción de prueba',
            'priority' => 'urgent',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['priority']);
    }

    public function test_rechaza_usuario_de_otra_organizacion(): void
    {
        $orgB = $this->prepareIncidentEnvironment('ACT-B');

        app(TenantContext::class)->clear();

        $orgA = $this->prepareIncidentEnvironment('ACT-A');
        $incidentId = $this->createIncidentForTests($orgA);

        $this->postJson('/api/corrective-actions', [
            'incident_id' => $incidentId,
            'assigned_to' => $orgB['user']->id,
            'description' => 'Asignación inválida',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['assigned_to']);
    }

    public function test_no_permite_consultar_accion_ajena(): void
    {
        $orgB = $this->prepareIncidentEnvironment('ACT-B');
        $incidentB = $this->createIncidentForTests($orgB);
        $actionB = $this->createCorrectiveActionForTests(
            $incidentB,
            $orgB['user']
        );

        app(TenantContext::class)->clear();

        $this->prepareIncidentEnvironment('ACT-A');

        $this->getJson("/api/corrective-actions/{$actionB}")
            ->assertNotFound();

        $this->patchJson(
            "/api/corrective-actions/{$actionB}",
            ['priority' => 'critical']
        )->assertNotFound();

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $actionB,
            'priority' => 'high',
        ]);
    }
}