<?php

namespace Tests\Feature\CorrectiveActions;

use App\Authorization\Permission;
use App\Models\CorrectiveAction;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class CorrectiveActionIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function environment(
        string $code = 'CA-A',
        array $permissions = [
            Permission::FindingsRegister,
            Permission::CorrectiveActionsManage,
        ]
    ): array {
        $organization = $this->organization($code);
        $user = $this->user($organization);

        app(TenantContext::class)->runAs(
            $organization->id,
            function () use ($organization, $user, $permissions): void {
                $role = $this->role(
                    $organization,
                    'ca-'.Str::random(12)
                );

                $user->assignRole($role);

                foreach ($permissions as $permission) {
                    RolePermission::create([
                        'id' => (string) Str::uuid7(),
                        'role_id' => $role->id,
                        'organization_id' => $organization->id,
                        'permission' => $permission->value,
                    ]);
                }
            }
        );

        $plant = $this->plant($organization, $code.'-P');
        $area = $this->area($plant, $code.'-A');

        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'area_id' => $area->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsApiInOrganization($user, $organization);

        return compact('organization', 'user', 'plant', 'area');
    }

    private function incident(array $env): string
    {
        return $this->postJson('/api/incidents', [
            'plant_id' => $env['plant']->id,
            'area_id' => $env['area']->id,
            'title' => 'Equipo sin protección',
            'description' => 'Se detectó una condición insegura.',
            'risk_level' => 'high',
        ])->assertCreated()->json('data.id');
    }

    private function payload(array $env, string $incidentId): array
    {
        return [
            'incident_id' => $incidentId,
            'assigned_to' => $env['user']->id,
            'description' => 'Instalar protección al equipo.',
            'priority' => 'high',
            'due_date' => '2026-12-15',
            'observations' => 'Atender con prioridad.',
        ];
    }

    private function createAction(array $env, string $incidentId): string
    {
        return $this->postJson(
            '/api/corrective-actions',
            $this->payload($env, $incidentId)
        )->assertCreated()->json('data.id');
    }

    private function auditCount(string $actionId): int
    {
        return DB::table('audit_logs')
            ->where('auditable_type', CorrectiveAction::class)
            ->where('auditable_id', $actionId)
            ->count();
    }

    public function test_crea_accion_y_registra_auditoria(): void
    {
        $env = $this->environment();
        $incidentId = $this->incident($env);
        $id = $this->createAction($env, $incidentId);

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $id,
            'organization_id' => $env['organization']->id,
            'incident_id' => $incidentId,
            'assigned_to' => $env['user']->id,
            'priority' => 'high',
            'status' => 'pending',
        ]);

        $this->assertSame(1, $this->auditCount($id));
    }

    public function test_consulta_y_filtra_acciones(): void
    {
        $env = $this->environment();
        $incidentId = $this->incident($env);
        $id = $this->createAction($env, $incidentId);

        $this->getJson("/api/corrective-actions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.updates', []);

        $this->getJson(
            '/api/corrective-actions?status=pending&per_page=10'
        )
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_actualiza_campos_y_audita(): void
    {
        $env = $this->environment();
        $incidentId = $this->incident($env);
        $id = $this->createAction($env, $incidentId);

        $this->patchJson("/api/corrective-actions/{$id}", [
            'description' => 'Instalar una nueva protección.',
            'priority' => 'critical',
        ])
            ->assertOk()
            ->assertJsonPath('data.priority', 'critical')
            ->assertJsonPath(
                'data.description',
                'Instalar una nueva protección.'
            );

        $this->assertSame(2, $this->auditCount($id));

        // Una actualización idéntica no genera auditoría adicional.
        $this->patchJson("/api/corrective-actions/{$id}", [
            'priority' => 'critical',
        ])->assertOk();

        $this->assertSame(2, $this->auditCount($id));
    }

    public function test_sin_permiso_de_gestion_no_puede_crear(): void
    {
        $env = $this->environment(
            'CA-A',
            [Permission::FindingsRegister]
        );

        $incidentId = $this->incident($env);

        $this->postJson(
            '/api/corrective-actions',
            $this->payload($env, $incidentId)
        )->assertForbidden();
    }

    public function test_sin_permiso_de_gestion_no_puede_modificar(): void
    {
        $env = $this->environment();
        $incidentId = $this->incident($env);
        $id = $this->createAction($env, $incidentId);

        DB::table('role_permissions')
            ->where('organization_id', $env['organization']->id)
            ->where('permission', Permission::CorrectiveActionsManage->value)
            ->delete();

        app(TenantContext::class)->flushPermissionCache(
            $env['user']->id,
            $env['organization']->id
        );

        $this->patchJson("/api/corrective-actions/{$id}", [
            'priority' => 'critical',
        ])->assertForbidden();

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $id,
            'priority' => 'high',
        ]);
    }

    public function test_organizacion_ajena_no_puede_consultar_ni_modificar(): void
    {
        $envA = $this->environment('CA-A');
        $incidentId = $this->incident($envA);
        $id = $this->createAction($envA, $incidentId);

        $this->environment('CA-B');

        $this->getJson("/api/corrective-actions/{$id}")
            ->assertNotFound();

        $this->patchJson("/api/corrective-actions/{$id}", [
            'priority' => 'critical',
        ])->assertNotFound();
    }

    public function test_sin_acceso_a_ubicacion_no_puede_consultar(): void
    {
        $env = $this->environment();
        $incidentId = $this->incident($env);
        $id = $this->createAction($env, $incidentId);

        DB::table('user_areas')
            ->where('user_id', $env['user']->id)
            ->delete();

        $this->getJson("/api/corrective-actions/{$id}")
            ->assertNotFound();

        $this->getJson('/api/corrective-actions')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_rechaza_campos_protegidos(): void
    {
        $env = $this->environment();
        $incidentId = $this->incident($env);

        $this->postJson('/api/corrective-actions', array_merge(
            $this->payload($env, $incidentId),
            ['status' => 'verified']
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');

        $id = $this->createAction($env, $incidentId);

        $this->patchJson("/api/corrective-actions/{$id}", [
            'organization_id' => $env['organization']->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');
    }

    public function test_rechaza_responsable_sin_membresia(): void
    {
        $env = $this->environment();
        $incidentId = $this->incident($env);
        $otherUser = $this->user($env['organization']);

        DB::table('organization_memberships')
            ->where('user_id', $otherUser->id)
            ->where('organization_id', $env['organization']->id)
            ->delete();

        $this->postJson('/api/corrective-actions', array_merge(
            $this->payload($env, $incidentId),
            ['assigned_to' => $otherUser->id]
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');
    }

    public function test_rechaza_incidente_de_otra_organizacion(): void
    {
        $envA = $this->environment('CA-A');
        $incidentId = $this->incident($envA);

        $envB = $this->environment('CA-B');

        $this->postJson(
            '/api/corrective-actions',
            $this->payload($envB, $incidentId)
        )->assertNotFound();
    }

    public function test_error_de_auditoria_revierte_creacion(): void
    {
        $env = $this->environment();
        $incidentId = $this->incident($env);

        $this->mock(AuditLogger::class)
            ->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('Auditoría fallida'));

        $this->postJson(
            '/api/corrective-actions',
            $this->payload($env, $incidentId)
        )->assertInternalServerError();

        $this->assertDatabaseCount('corrective_actions', 0);
    }

    public function test_accion_correctiva_permanece_accesible_con_ubicacion_inactiva(): void
    {
        $env = $this->environment();

        $incidentId = $this->incident($env);
        $id = $this->createAction($env, $incidentId);

        DB::table('plants')
            ->where('id', $env['plant']->id)
            ->update(['status' => false]);

        DB::table('areas')
            ->where('id', $env['area']->id)
            ->update(['status' => false]);

        $this->getJson("/api/corrective-actions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.incident_id', $incidentId)
            ->assertJsonPath('data.status', 'pending');

        $this->getJson('/api/corrective-actions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id);

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $id,
            'incident_id' => $incidentId,
            'status' => 'pending',
        ]);
    }

    public function test_accion_correctiva_puede_modificarse_con_ubicacion_inactiva(): void
    {
        $env = $this->environment();

        $incidentId = $this->incident($env);
        $id = $this->createAction($env, $incidentId);

        DB::table('plants')
            ->where('id', $env['plant']->id)
            ->update(['status' => false]);

        DB::table('areas')
            ->where('id', $env['area']->id)
            ->update(['status' => false]);

        $this->patchJson("/api/corrective-actions/{$id}", [
            'description' => 'Completar medida correctiva pendiente.',
            'priority' => 'critical',
        ])
            ->assertOk()
            ->assertJsonPath('data.priority', 'critical')
            ->assertJsonPath(
                'data.description',
                'Completar medida correctiva pendiente.',
            );

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $id,
            'incident_id' => $incidentId,
            'priority' => 'critical',
        ]);

        $this->assertSame(2, $this->auditCount($id));
    }
}
