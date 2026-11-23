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

class CorrectiveActionUpdateIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function environment(
        string $code = 'CAU-A'
    ): array {
        $organization = $this->organization($code);
        $user = $this->user($organization);

        app(TenantContext::class)->runAs(
            $organization->id,
            function () use ($organization, $user): void {
                $role = $this->role(
                    $organization,
                    'cau-'.Str::random(12)
                );

                $user->assignRole($role);

                foreach ([
                    Permission::FindingsRegister,
                    Permission::CorrectiveActionsManage,
                ] as $permission) {
                    RolePermission::create([
                        'id' => (string) Str::uuid7(),
                        'role_id' => $role->id,
                        'organization_id' => $organization->id,
                        'permission' => $permission->value,
                    ]);
                }
            }
        );

        $verifier = $this->user($organization);

        app(TenantContext::class)->runAs(
            $organization->id,
            function () use ($organization, $verifier): void {
                $role = $this->role(
                    $organization,
                    'cau-verify-'.Str::random(12)
                );

                $verifier->assignRole($role);

                foreach ([
                    Permission::FindingsRegister,
                    Permission::CorrectiveActionsVerify,
                ] as $permission) {
                    RolePermission::create([
                        'id' => (string) Str::uuid7(),
                        'role_id' => $role->id,
                        'organization_id' => $organization->id,
                        'permission' => $permission->value,
                    ]);
                }

                DB::table('roles')
                    ->where('id', $role->id)
                    ->update(['system_key' => 'supervisor']);
            }
        );

        $plant = $this->plant(
            $organization,
            $code.'-P'
        );

        $area = $this->area(
            $plant,
            $code.'-A'
        );

        foreach ([$user, $verifier] as $member) {
            DB::table('user_areas')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->id,
                'user_id' => $member->id,
                'area_id' => $area->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAsApiInOrganization(
            $user,
            $organization
        );

        return compact(
            'organization',
            'user',
            'verifier',
            'plant',
            'area'
        );
    }

    private function createAction(array $env): string
    {
        $incidentId = $this->postJson('/api/incidents', [
            'plant_id' => $env['plant']->id,
            'area_id' => $env['area']->id,
            'title' => 'Protección defectuosa',
            'description' => 'Se detectó una condición insegura.',
            'risk_level' => 'high',
        ])->assertCreated()->json('data.id');

        return $this->postJson('/api/corrective-actions', [
            'incident_id' => $incidentId,
            'assigned_to' => $env['user']->id,
            'description' => 'Reemplazar la protección.',
            'priority' => 'high',
        ])->assertCreated()->json('data.id');
    }

    private function advance(
        string $actionId,
        string $status
    ): void {
        $this->postJson(
            "/api/corrective-actions/{$actionId}/updates",
            [
                'status' => $status,
                'comment' => "Avance a {$status}",
            ]
        )
            ->assertCreated()
            ->assertJsonPath('data.status', $status);
    }

    private function auditCount(string $actionId): int
    {
        return DB::table('audit_logs')
            ->where('auditable_type', CorrectiveAction::class)
            ->where('auditable_id', $actionId)
            ->where(
                'action',
                'corrective_action.status_changed'
            )
            ->count();
    }

    public function test_registra_avance_y_auditoria(): void
    {
        $env = $this->environment();
        $id = $this->createAction($env);

        $this->advance($id, 'in_progress');

        $this->assertDatabaseHas(
            'corrective_actions',
            [
                'id' => $id,
                'status' => 'in_progress',
            ]
        );

        $this->assertDatabaseHas(
            'corrective_action_updates',
            [
                'corrective_action_id' => $id,
                'user_id' => $env['user']->id,
                'status' => 'in_progress',
            ]
        );

        $this->assertSame(1, $this->auditCount($id));
    }

    public function test_completa_y_verifica_accion(): void
    {
        $env = $this->environment();
        $id = $this->createAction($env);

        $this->advance($id, 'in_progress');
        $this->advance($id, 'completed');

        $action = CorrectiveAction::findOrFail($id);

        $this->assertSame('completed', $action->status);
        $this->assertNotNull($action->completed_at);

        $completedAt = $action->completed_at->toISOString();

        $this->actingAsApiInOrganization(
            $env['verifier'],
            $env['organization']
        );

        $this->advance($id, 'verified');

        $action->refresh();

        $this->assertSame('verified', $action->status);
        $this->assertSame(
            $completedAt,
            $action->completed_at->toISOString()
        );

        $this->assertSame(3, $this->auditCount($id));
    }

    public function test_lista_historial_en_orden(): void
    {
        $env = $this->environment();
        $id = $this->createAction($env);

        $this->advance($id, 'in_progress');
        $this->advance($id, 'completed');

        $this->getJson(
            "/api/corrective-actions/{$id}/updates?per_page=10"
        )
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.status', 'in_progress')
            ->assertJsonPath('data.1.status', 'completed');
    }

    public function test_no_permite_saltar_estados(): void
    {
        $env = $this->environment();
        $id = $this->createAction($env);

        $this->postJson(
            "/api/corrective-actions/{$id}/updates",
            ['status' => 'verified']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas(
            'corrective_actions',
            ['id' => $id, 'status' => 'pending']
        );

        $this->assertDatabaseCount(
            'corrective_action_updates',
            0
        );
    }

    public function test_no_permite_repetir_estado(): void
    {
        $env = $this->environment();
        $id = $this->createAction($env);

        $this->advance($id, 'in_progress');

        $this->postJson(
            "/api/corrective-actions/{$id}/updates",
            ['status' => 'in_progress']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseCount(
            'corrective_action_updates',
            1
        );
    }

    public function test_no_permite_avances_tras_verificacion(): void
    {
        $env = $this->environment();
        $id = $this->createAction($env);

        $this->advance($id, 'in_progress');
        $this->advance($id, 'completed');

        $this->actingAsApiInOrganization(
            $env['verifier'],
            $env['organization']
        );
        $this->advance($id, 'verified');

        $this->actingAsApiInOrganization(
            $env['user'],
            $env['organization']
        );

        $this->postJson(
            "/api/corrective-actions/{$id}/updates",
            ['status' => 'in_progress']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseCount(
            'corrective_action_updates',
            3
        );
    }

    public function test_sin_permiso_no_puede_registrar_avance(): void
    {
        $env = $this->environment();
        $id = $this->createAction($env);

        DB::table('role_permissions')
            ->where('organization_id', $env['organization']->id)
            ->where(
                'permission',
                Permission::CorrectiveActionsManage->value
            )
            ->delete();

        app(TenantContext::class)->flushPermissionCache(
            $env['user']->id,
            $env['organization']->id
        );

        $this->postJson(
            "/api/corrective-actions/{$id}/updates",
            ['status' => 'in_progress']
        )->assertForbidden();

        $this->assertDatabaseCount(
            'corrective_action_updates',
            0
        );
    }

    public function test_organizacion_ajena_no_accede_al_historial(): void
    {
        $envA = $this->environment('CAU-A');
        $id = $this->createAction($envA);

        $this->environment('CAU-B');

        $this->getJson(
            "/api/corrective-actions/{$id}/updates"
        )->assertNotFound();

        $this->postJson(
            "/api/corrective-actions/{$id}/updates",
            ['status' => 'in_progress']
        )->assertNotFound();
    }

    public function test_sin_ubicacion_no_accede_al_historial(): void
    {
        $env = $this->environment();
        $id = $this->createAction($env);

        DB::table('user_areas')
            ->where('user_id', $env['user']->id)
            ->delete();

        $this->getJson(
            "/api/corrective-actions/{$id}/updates"
        )->assertNotFound();

        $this->postJson(
            "/api/corrective-actions/{$id}/updates",
            ['status' => 'in_progress']
        )->assertNotFound();
    }

    public function test_rechaza_campos_protegidos(): void
    {
        $env = $this->environment();
        $id = $this->createAction($env);

        $this->postJson(
            "/api/corrective-actions/{$id}/updates",
            [
                'status' => 'in_progress',
                'user_id' => $env['user']->id,
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');

        $this->assertDatabaseCount(
            'corrective_action_updates',
            0
        );
    }

    public function test_error_de_auditoria_revierte_avance(): void
    {
        $env = $this->environment();
        $id = $this->createAction($env);

        $this->mock(AuditLogger::class)
            ->shouldReceive('record')
            ->once()
            ->andThrow(
                new RuntimeException('Auditoría fallida')
            );

        $this->postJson(
            "/api/corrective-actions/{$id}/updates",
            ['status' => 'in_progress']
        )->assertInternalServerError();

        $this->assertDatabaseHas(
            'corrective_actions',
            ['id' => $id, 'status' => 'pending']
        );

        $this->assertDatabaseCount(
            'corrective_action_updates',
            0
        );
    }
}
