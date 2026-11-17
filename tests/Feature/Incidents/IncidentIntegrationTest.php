<?php

namespace Tests\Feature\Incidents;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class IncidentIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function environment(
        string $code = 'INC-A',
        array $permissions = [
            Permission::FindingsRegister,
            Permission::FindingsClose,
        ]
    ): array {
        $organization = $this->organization($code);
        $user = $this->user($organization);
        app(TenantContext::class)->runAs($organization->id, function () use ($organization, $user, $permissions): void {
            $role = $this->role($organization, 'inc-' . Str::random(12));
            $user->assignRole($role);
            foreach ($permissions as $permission) {
                RolePermission::create([
                    'id' => (string) Str::uuid7(),
                    'role_id' => $role->id,
                    'organization_id' => $organization->id,
                    'permission' => $permission->value,
                ]);
            }
        });
        $plant = $this->plant($organization, $code . '-P');
        $area = $this->area($plant, $code . '-A');
        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'area_id' => $area->id,
            'organization_id' => $organization->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsApiInOrganization($user, $organization);

        return compact('organization', 'user', 'plant', 'area');
    }

    private function payload(array $env): array
    {
        return [
            'plant_id' => $env['plant']->id,
            'area_id' => $env['area']->id,
            'type' => 'unsafe_condition',
            'title' => 'Extintor en mal estado',
            'description' => 'El extintor presenta daños visibles.',
            'risk_level' => 'high',
        ];
    }

    private function createIncident(array $env): string
    {
        return $this->postJson('/api/incidents', $this->payload($env))
            ->assertCreated()->json('data.id');
    }

    public function test_registra_incidente_y_auditoria(): void
    {
        $env = $this->environment();
        $id = $this->createIncident($env);

        $this->assertDatabaseHas('incidents', [
            'id' => $id,
            'organization_id' => $env['organization']->id,
            'reported_by' => $env['user']->id,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $env['organization']->id,
            'auditable_id' => $id,
            'action' => 'incident.created',
        ]);
    }

    public function test_consulta_y_lista_incidentes(): void
    {
        $env = $this->environment();
        $id = $this->createIncident($env);

        $this->getJson("/api/incidents/{$id}")
            ->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/incidents?risk_level=high&per_page=10')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_actualiza_estado_en_orden_y_audita(): void
    {
        $env = $this->environment();
        $id = $this->createIncident($env);

        foreach (['in_progress', 'resolved', 'closed'] as $status) {
            $this->patchJson("/api/incidents/{$id}", [
                'status' => $status,
            ])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        // Solo contamos los registros de auditoría del incidente.
        $this->assertSame(
            4,
            DB::table('audit_logs')
                ->where('auditable_type', \App\Models\Incident::class)
                ->where('auditable_id', $id)
                ->count()
        );

        // Repetir el mismo estado no debe generar otra auditoría.
        $this->patchJson("/api/incidents/{$id}", [
            'status' => 'closed',
        ])->assertOk();

        $this->assertSame(
            4,
            DB::table('audit_logs')
                ->where('auditable_type', \App\Models\Incident::class)
                ->where('auditable_id', $id)
                ->count()
        );

        // No se permite regresar a un estado anterior.
        $this->patchJson("/api/incidents/{$id}", [
            'status' => 'open',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_registrador_no_puede_cambiar_estado_sin_permiso_de_cierre(): void
    {
        $env = $this->environment('INC-A', [Permission::FindingsRegister]);
        $id = $this->createIncident($env);
        $this->patchJson("/api/incidents/{$id}", ['status' => 'in_progress'])
            ->assertForbidden();
        $this->assertDatabaseHas('incidents', ['id' => $id, 'status' => 'open']);
    }

    public function test_sin_permiso_no_puede_registrar(): void
    {
        $env = $this->environment('INC-A', []);
        $this->postJson('/api/incidents', $this->payload($env))->assertForbidden();
    }

    public function test_organizacion_ajena_no_puede_ver_o_modificar(): void
    {
        $envA = $this->environment('INC-A');
        $id = $this->createIncident($envA);
        $this->environment('INC-B');

        $this->getJson("/api/incidents/{$id}")->assertNotFound();
        $this->patchJson("/api/incidents/{$id}", ['status' => 'in_progress'])
            ->assertNotFound();
    }

    public function test_sin_asignacion_a_ubicacion_no_puede_ver_incidente(): void
    {
        $env = $this->environment();
        $id = $this->createIncident($env);
        DB::table('user_areas')->where('user_id', $env['user']->id)->delete();
        $this->getJson("/api/incidents/{$id}")->assertNotFound();
        $this->getJson('/api/incidents')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_rechaza_ubicacion_incoherente_y_campos_protegidos(): void
    {
        $env = $this->environment();
        $secondPlant = $this->plant($env['organization'], 'INC-OTRA');
        $this->postJson('/api/incidents', array_merge($this->payload($env), [
            'plant_id' => $secondPlant->id,
        ]))->assertNotFound();
        $this->postJson('/api/incidents', array_merge($this->payload($env), [
            'reported_by' => $env['user']->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('fields');
    }

    public function test_rechaza_riesgo_invalido_y_estado_desconocido(): void
    {
        $env = $this->environment();
        $this->postJson('/api/incidents', array_merge($this->payload($env), [
            'risk_level' => 'extreme',
        ]))->assertUnprocessable()->assertJsonValidationErrors('risk_level');
        $id = $this->createIncident($env);
        $this->patchJson("/api/incidents/{$id}", ['status' => 'cancelled'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_error_de_auditoria_revierte_creacion(): void
    {
        $env = $this->environment();
        $this->mock(AuditLogger::class)
            ->shouldReceive('record')->once()->andThrow(new RuntimeException('Auditoría fallida'));
        $this->postJson('/api/incidents', $this->payload($env))
            ->assertInternalServerError();
        $this->assertDatabaseCount('incidents', 0);
    }
}
