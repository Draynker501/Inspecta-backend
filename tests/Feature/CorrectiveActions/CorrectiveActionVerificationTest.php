<?php

namespace Tests\Feature\CorrectiveActions;

use App\Authorization\Permission;
use App\Models\CorrectiveAction;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class CorrectiveActionVerificationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function grant(
        User $user,
        Organization $organization,
        array $permissions,
        ?string $systemKey = null
    ): Role {
        $role = app(TenantContext::class)->runAs($organization->id, function () use ($user, $organization, $permissions, $systemKey): Role {
            $role = $this->role($organization, 'VerifyRole-'.Str::random(10));
            $user->assignRole($role);

            foreach ($permissions as $permission) {
                RolePermission::firstOrCreate(
                    [
                        'role_id' => $role->id,
                        'organization_id' => $organization->id,
                        'permission' => $permission->value,
                    ],
                    ['id' => (string) Str::uuid7()]
                );
            }

            if ($systemKey !== null) {
                DB::table('roles')
                    ->where('id', $role->id)
                    ->update(['system_key' => $systemKey]);
            }

            return $role;
        });

        app(TenantContext::class)->flushPermissionCache($user->id, $organization->id);

        return $role;
    }

    private function environment(): array
    {
        $organization = $this->clientOrganization('VER-A');
        $manager = $this->user($organization);
        $executor = $this->user($organization);
        $verifier = $this->user($organization);
        $inspector = $this->user($organization);

        $plant = $this->plant($organization, 'VER-P');
        $area = $this->area($plant, 'VER-A');

        foreach ([$manager, $executor, $verifier, $inspector] as $member) {
            DB::table('user_areas')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->id,
                'user_id' => $member->id,
                'area_id' => $area->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->grant($manager, $organization, [
            Permission::FindingsRegister,
            Permission::CorrectiveActionsManage,
        ]);
        $verifierRole = $this->grant($verifier, $organization, [
            Permission::FindingsRegister,
            Permission::CorrectiveActionsVerify,
        ], 'supervisor');
        $this->grant($inspector, $organization, [
            Permission::FindingsRegister,
        ]);

        $this->actingAsApiInOrganization($manager, $organization);

        return compact(
            'organization',
            'manager',
            'executor',
            'verifier',
            'verifierRole',
            'inspector',
            'plant',
            'area'
        );
    }

    private function as(User $user, Organization $organization): void
    {
        $this->actingAsApiInOrganization($user, $organization);
    }

    private function advance(string $actionId, string $status): void
    {
        $this->postJson(
            "/api/corrective-actions/{$actionId}/updates",
            ['status' => $status, 'comment' => "Avance a {$status}"]
        )
            ->assertCreated()
            ->assertJsonPath('data.status', $status);
    }

    private function completedAction(array $env): string
    {
        $this->as($env['manager'], $env['organization']);

        $incidentId = $this->postJson('/api/incidents', [
            'plant_id' => $env['plant']->id,
            'area_id' => $env['area']->id,
            'title' => 'Protección defectuosa para verificación',
            'description' => 'Se detectó una condición insegura.',
            'risk_level' => 'high',
        ])->assertCreated()->json('data.id');

        $actionId = $this->postJson('/api/corrective-actions', [
            'incident_id' => $incidentId,
            'assigned_to' => $env['executor']->id,
            'description' => 'Reemplazar la protección.',
            'priority' => 'high',
        ])->assertCreated()->json('data.id');

        $this->advance($actionId, 'in_progress');
        $this->advance($actionId, 'completed');

        return $actionId;
    }

    public function test_inspector_sin_verify_no_puede_verificar(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);

        $this->as($env['inspector'], $env['organization']);

        $this->postJson("/api/corrective-actions/{$id}/updates", [
            'status' => 'verified',
            'comment' => 'Intento sin permiso',
        ])
            ->assertForbidden();

        $this->assertDatabaseHas('corrective_actions', ['id' => $id, 'status' => 'completed']);
        $this->assertDatabaseMissing('corrective_action_updates', [
            'corrective_action_id' => $id,
            'status' => 'verified',
        ]);
    }

    public function test_verificador_autorizado_puede_verificar_y_se_audita(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);
        $completedAt = CorrectiveAction::findOrFail($id)->completed_at->toISOString();

        $this->as($env['verifier'], $env['organization']);

        $this->postJson("/api/corrective-actions/{$id}/updates", [
            'status' => 'verified',
            'comment' => 'Verificado en campo',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.user_id', $env['verifier']->id);

        $action = CorrectiveAction::findOrFail($id);
        $this->assertSame('verified', $action->status);
        $this->assertSame($completedAt, $action->completed_at->toISOString());

        $this->assertDatabaseHas('corrective_action_updates', [
            'corrective_action_id' => $id,
            'user_id' => $env['verifier']->id,
            'status' => 'verified',
        ]);
        $this->assertDatabaseHas('corrective_action_updates', [
            'corrective_action_id' => $id,
            'user_id' => $env['manager']->id,
            'status' => 'completed',
        ]);

        $audit = DB::table('audit_logs')
            ->where('auditable_type', CorrectiveAction::class)
            ->where('auditable_id', $id)
            ->where('action', 'corrective_action.status_changed')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($env['verifier']->id, $audit->user_id);
        $newValues = json_decode($audit->new_values, true);
        $this->assertSame($env['verifier']->id, $newValues['verified_by']);
        $this->assertSame($env['manager']->id, $newValues['completed_by']);
    }

    public function test_administrador_con_verify_tambien_puede_verificar(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);
        $admin = $this->user($env['organization']);
        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $env['organization']->id,
            'user_id' => $admin->id,
            'area_id' => $env['area']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->grant($admin, $env['organization'], [
            Permission::FindingsRegister,
            Permission::CorrectiveActionsVerify,
        ], 'admin');

        $this->as($admin, $env['organization']);
        $this->advance($id, 'verified');
    }

    public function test_ejecutor_no_puede_autoverificar_su_trabajo(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);
        $this->grant($env['manager'], $env['organization'], [
            Permission::CorrectiveActionsVerify,
        ], 'admin');

        $this->as($env['manager'], $env['organization']);

        $this->postJson("/api/corrective-actions/{$id}/updates", ['status' => 'verified'])
            ->assertForbidden();

        $this->assertDatabaseHas('corrective_actions', ['id' => $id, 'status' => 'completed']);
    }

    public function test_responsable_asignado_no_puede_verificar(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);
        $this->grant($env['executor'], $env['organization'], [
            Permission::FindingsRegister,
            Permission::CorrectiveActionsVerify,
        ], 'admin');

        $this->as($env['executor'], $env['organization']);

        $this->postJson("/api/corrective-actions/{$id}/updates", ['status' => 'verified'])
            ->assertForbidden();

        $this->assertDatabaseHas('corrective_actions', ['id' => $id, 'status' => 'completed']);
    }

    public function test_reasignacion_tras_completed_devuelve_422_y_no_altera_autor(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);

        $this->as($env['manager'], $env['organization']);
        $this->patchJson("/api/corrective-actions/{$id}", [
            'assigned_to' => $env['verifier']->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');

        $this->assertDatabaseHas('corrective_actions', [
            'id' => $id,
            'assigned_to' => $env['executor']->id,
        ]);

        $this->as($env['verifier'], $env['organization']);
        $this->advance($id, 'verified');
    }

    public function test_organizacion_ajena_no_puede_verificar(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);

        $orgB = $this->clientOrganization('VER-B');
        $verifierB = $this->user($orgB);
        $plantB = $this->plant($orgB, 'VER-B-P');
        $areaB = $this->area($plantB, 'VER-B-A');
        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $orgB->id,
            'user_id' => $verifierB->id,
            'area_id' => $areaB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->grant($verifierB, $orgB, [
            Permission::FindingsRegister,
            Permission::CorrectiveActionsVerify,
        ], 'supervisor');

        $this->as($verifierB, $orgB);

        $this->postJson("/api/corrective-actions/{$id}/updates", ['status' => 'verified'])
            ->assertNotFound();

        $this->assertDatabaseHas('corrective_actions', ['id' => $id, 'status' => 'completed']);
    }

    public function test_membresia_revocada_no_puede_verificar(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);

        OrganizationMembership::where('user_id', $env['verifier']->id)
            ->where('organization_id', $env['organization']->id)
            ->update([
                'status' => OrganizationMembership::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);

        $this->as($env['verifier'], $env['organization']);

        $this->postJson("/api/corrective-actions/{$id}/updates", ['status' => 'verified'])
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_access_denied');
    }

    public function test_estado_distinto_de_completed_devuelve_422(): void
    {
        $env = $this->environment();
        $this->as($env['manager'], $env['organization']);

        $incidentId = $this->postJson('/api/incidents', [
            'plant_id' => $env['plant']->id,
            'area_id' => $env['area']->id,
            'title' => 'Acción pendiente',
            'description' => 'Sin completar.',
            'risk_level' => 'medium',
        ])->assertCreated()->json('data.id');

        $id = $this->postJson('/api/corrective-actions', [
            'incident_id' => $incidentId,
            'assigned_to' => $env['executor']->id,
            'description' => 'Acción pendiente.',
        ])->assertCreated()->json('data.id');

        $this->as($env['verifier'], $env['organization']);

        $this->postJson("/api/corrective-actions/{$id}/updates", ['status' => 'verified'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_verificacion_repetida_devuelve_422_sin_nuevo_avance(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);

        $this->as($env['verifier'], $env['organization']);
        $this->advance($id, 'verified');

        $this->postJson("/api/corrective-actions/{$id}/updates", ['status' => 'verified'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(3, DB::table('corrective_action_updates')
            ->where('corrective_action_id', $id)->count());
    }

    public function test_sin_avance_completed_confiable_deniega_verificacion(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);

        DB::table('corrective_action_updates')
            ->where('corrective_action_id', $id)
            ->where('status', 'completed')
            ->delete();

        $this->as($env['verifier'], $env['organization']);

        $this->postJson("/api/corrective-actions/{$id}/updates", ['status' => 'verified'])
            ->assertForbidden();

        $this->assertDatabaseHas('corrective_actions', ['id' => $id, 'status' => 'completed']);
    }

    public function test_inspector_con_verify_concedido_incorrectamente_no_es_elegible(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);
        $this->grant($env['inspector'], $env['organization'], [
            Permission::CorrectiveActionsVerify,
        ], 'inspector');

        $this->as($env['inspector'], $env['organization']);

        $this->postJson("/api/corrective-actions/{$id}/updates", ['status' => 'verified'])
            ->assertForbidden();

        $this->assertDatabaseHas('corrective_actions', ['id' => $id, 'status' => 'completed']);
    }

    public function test_rol_personalizado_sin_system_key_no_puede_verificar(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);
        $custom = $this->user($env['organization']);
        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $env['organization']->id,
            'user_id' => $custom->id,
            'area_id' => $env['area']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->grant($custom, $env['organization'], [
            Permission::FindingsRegister,
            Permission::CorrectiveActionsVerify,
        ]);

        $this->as($custom, $env['organization']);

        $this->postJson("/api/corrective-actions/{$id}/updates", ['status' => 'verified'])
            ->assertForbidden();
    }

    public function test_rol_renombrado_con_system_key_conserva_elegibilidad(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);

        DB::table('roles')
            ->where('id', $env['verifierRole']->id)
            ->update(['name' => 'Supervisor Renombrado']);

        $this->as($env['verifier'], $env['organization']);
        $this->advance($id, 'verified');
    }

    public function test_usuario_con_varios_roles_conserva_elegibilidad_si_uno_es_base(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);
        $this->grant($env['verifier'], $env['organization'], []);

        $this->as($env['verifier'], $env['organization']);
        $this->advance($id, 'verified');
    }

    public function test_rol_inactivo_no_es_elegible(): void
    {
        $env = $this->environment();
        $id = $this->completedAction($env);

        DB::table('roles')
            ->where('id', $env['verifierRole']->id)
            ->update(['status' => false]);

        $this->as($env['verifier'], $env['organization']);

        $this->postJson("/api/corrective-actions/{$id}/updates", ['status' => 'verified'])
            ->assertForbidden();
    }

    public function test_cambio_de_tenant_recalcula_elegibilidad(): void
    {
        $env = $this->environment();
        $actionA = $this->completedAction($env);

        $orgB = $this->clientOrganization('VER-TENANT-B');
        $managerB = $this->user($orgB);
        $executorB = $this->user($orgB);
        $plantB = $this->plant($orgB, 'VER-TB-P');
        $areaB = $this->area($plantB, 'VER-TB-A');

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $env['verifier']->id,
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        foreach ([$managerB, $executorB, $env['verifier']] as $member) {
            DB::table('user_areas')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $orgB->id,
                'user_id' => $member->id,
                'area_id' => $areaB->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->grant($managerB, $orgB, [
            Permission::FindingsRegister,
            Permission::CorrectiveActionsManage,
        ]);
        $this->grant($env['verifier'], $orgB, [
            Permission::FindingsRegister,
            Permission::CorrectiveActionsVerify,
        ]);

        $this->as($managerB, $orgB);
        $incidentB = $this->postJson('/api/incidents', [
            'plant_id' => $plantB->id,
            'area_id' => $areaB->id,
            'title' => 'Acción en tenant B',
            'description' => 'Acción completada para aislamiento.',
            'risk_level' => 'medium',
        ])->assertCreated()->json('data.id');
        $actionB = $this->postJson('/api/corrective-actions', [
            'incident_id' => $incidentB,
            'assigned_to' => $executorB->id,
            'description' => 'Acción B.',
        ])->assertCreated()->json('data.id');
        $this->advance($actionB, 'in_progress');
        $this->advance($actionB, 'completed');

        // En B el rol del verificador no tiene system_key: no es elegible.
        $this->as($env['verifier'], $orgB);
        $this->postJson("/api/corrective-actions/{$actionB}/updates", ['status' => 'verified'])
            ->assertForbidden();

        // El mismo usuario conserva elegibilidad en A por su rol Supervisor.
        $this->as($env['verifier'], $env['organization']);
        $this->advance($actionA, 'verified');
    }
}
