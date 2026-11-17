<?php

namespace Tests\Feature\Incidents;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class CorrectiveActionUpdateTest extends TestCase
{
    use RefreshDatabase;
    use IncidentFixtures;

    private function prepareAction(): array
    {
        $env = $this->prepareIncidentEnvironment();
        $incidentId = $this->createIncidentForTests($env);
        $actionId = $this->createCorrectiveActionForTests(
            $incidentId,
            $env['user']
        );

        return [$env, $actionId];
    }

    public function test_puede_iniciar_accion(): void
    {
        [$env, $actionId] = $this->prepareAction();

        $response = $this->postJson(
            "/api/corrective-actions/{$actionId}/updates",
            [
                'status' => 'in_progress',
                'comment' => 'Se inició la corrección.',
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath(
                'data.user_id',
                $env['user']->id
            );

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $actionId,
            'status' => 'in_progress',
        ]);

        $this->assertDatabaseHas('corrective_action_updates', [
            'corrective_action_id' => $actionId,
            'status' => 'in_progress',
            'user_id' => $env['user']->id,
        ]);
    }

    public function test_completar_accion_establece_fecha(): void
    {
        [, $actionId] = $this->prepareAction();

        $url = "/api/corrective-actions/{$actionId}/updates";

        $this->postJson($url, [
            'status' => 'in_progress',
        ])->assertCreated();

        $this->postJson($url, [
            'status' => 'completed',
            'comment' => 'Trabajo terminado.',
        ])->assertCreated();

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $actionId,
            'status' => 'completed',
        ]);

        $this->assertNotNull(
            \Illuminate\Support\Facades\DB::table(
                'corrective_actions'
            )->where('id', $actionId)->value('completed_at')
        );
    }

    public function test_verificar_conserva_fecha_de_finalizacion(): void
    {
        [, $actionId] = $this->prepareAction();

        $url = "/api/corrective-actions/{$actionId}/updates";

        $this->postJson($url, [
            'status' => 'in_progress',
        ])->assertCreated();

        $this->postJson($url, [
            'status' => 'completed',
        ])->assertCreated();

        $completedAt = \Illuminate\Support\Facades\DB::table(
            'corrective_actions'
        )->where('id', $actionId)->value('completed_at');

        $this->postJson($url, [
            'status' => 'verified',
        ])->assertCreated();

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $actionId,
            'status' => 'verified',
            'completed_at' => $completedAt,
        ]);
    }

    public function test_rechaza_saltar_estados(): void
    {
        [, $actionId] = $this->prepareAction();

        $this->postJson(
            "/api/corrective-actions/{$actionId}/updates",
            ['status' => 'verified']
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $actionId,
            'status' => 'pending',
        ]);

        $this->assertDatabaseCount(
            'corrective_action_updates',
            0
        );
    }

    public function test_rechaza_retroceso_a_pending(): void
    {
        [, $actionId] = $this->prepareAction();

        $url = "/api/corrective-actions/{$actionId}/updates";

        $this->postJson($url, [
            'status' => 'in_progress',
        ])->assertCreated();

        $this->postJson($url, [
            'status' => 'pending',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $actionId,
            'status' => 'in_progress',
        ]);
    }


    public function test_historial_es_cronologico(): void
    {
        [, $actionId] = $this->prepareAction();

        $url = "/api/corrective-actions/{$actionId}/updates";

        $fechaInicial = \Carbon\Carbon::parse(
            '2026-11-16 10:00:00'
        );

        $estados = [
            'in_progress',
            'completed',
            'verified',
        ];

        foreach ($estados as $indice => $status) {
            // Cada actualización ocurre un minuto después.
            $this->travelTo(
                $fechaInicial->copy()->addMinutes($indice)
            );

            $this->postJson($url, [
                'status' => $status,
            ])->assertCreated();
        }

        // Restaurar el reloj antes de consultar el historial.
        $this->travelBack();

        $response = $this->getJson($url);

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.status', 'in_progress')
            ->assertJsonPath('data.1.status', 'completed')
            ->assertJsonPath('data.2.status', 'verified')
            ->assertJsonPath('meta.total', 3);

        // Verificar que las fechas estén ordenadas.
        $updates = $response->json('data');

        $this->assertTrue(
            \Carbon\Carbon::parse($updates[0]['created_at'])
                ->lessThan(
                    \Carbon\Carbon::parse($updates[1]['created_at'])
                )
        );

        $this->assertTrue(
            \Carbon\Carbon::parse($updates[1]['created_at'])
                ->lessThan(
                    \Carbon\Carbon::parse($updates[2]['created_at'])
                )
        );

        // Confirmar que la acción terminó en el estado esperado.
        $this->assertDatabaseHas('corrective_actions', [
            'id' => $actionId,
            'status' => 'verified',
        ]);
    }

    public function test_no_permite_actualizar_accion_ajena(): void
    {
        $orgB = $this->prepareIncidentEnvironment('UPD-B');
        $incidentB = $this->createIncidentForTests($orgB);
        $actionB = $this->createCorrectiveActionForTests(
            $incidentB,
            $orgB['user']
        );

        app(TenantContext::class)->clear();

        $this->prepareIncidentEnvironment('UPD-A');

        $url = "/api/corrective-actions/{$actionB}/updates";

        $this->postJson($url, [
            'status' => 'in_progress',
        ])->assertNotFound();

        $this->getJson($url)->assertNotFound();

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $actionB,
            'status' => 'pending',
        ]);
    }
}