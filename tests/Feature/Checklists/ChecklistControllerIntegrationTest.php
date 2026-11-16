<?php

namespace Tests\Feature\Checklists;

use App\Authorization\Permission;
use App\Models\AuditLog;
use App\Models\Checklist;
use App\Models\OrganizationMembership;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ChecklistControllerIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_autorizacion_precede_validacion_y_resolucion(): void
    {
        $org = $this->organization('CHECK-AUTH');
        $user = $this->user($org);
        $this->getJson('/api/checklists')->assertUnauthorized();
        $this->actingAsApi($user);
        $this->getJson('/api/checklists')->assertBadRequest();
        $this->withHeader('X-Organization-Id', $org->id);
        $this->getJson('/api/checklists?per_page=0')->assertForbidden();
        $this->getJson('/api/checklists/missing')->assertForbidden();
        $this->postJson('/api/checklists', [])->assertForbidden();
    }

    public function test_lectura_aislada_y_creacion_auditada_con_permisos_independientes(): void
    {
        $org = $this->organization('CHECK-A');
        $other = $this->organization('CHECK-B');
        $user = $this->user($org);
        $role = $this->role($org, 'checklist-test');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $this->grant($role->id, $org->id, Permission::ChecklistsManage);
        $foreign = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $other->id, 'name' => 'Ajeno', 'status' => true]);
        $this->actingAsApiInOrganization($user, $org);

        $this->getJson('/api/checklists')->assertForbidden();
        $this->postJson('/api/checklists', [])->assertUnprocessable();
        $this->postJson('/api/checklists', ['name' => 'Local', 'organization_id' => $other->id])->assertUnprocessable();
        $response = $this->postJson('/api/checklists', ['name' => 'Local', 'description' => 'Prueba'])->assertCreated();
        $id = $response->json('data.id');
        $response->assertJsonPath('data.organization_id', $org->id)->assertJsonPath('data.status', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'checklist.created', 'user_id' => $user->id, 'organization_id' => $org->id, 'auditable_id' => $id]);
        $audit = AuditLog::where('action', 'checklist.created')->sole();
        $this->assertSame(['name' => 'Local', 'status' => true], $audit->new_values->all());

        $this->grant($role->id, $org->id, Permission::ChecklistsView);
        $this->getJson('/api/checklists?per_page=0')->assertUnprocessable();
        $this->getJson('/api/checklists')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/checklists/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/checklists/'.$foreign->id)->assertNotFound();

        OrganizationMembership::where('organization_id', $org->id)->where('user_id', $user->id)
            ->update(['status' => OrganizationMembership::STATUS_REVOKED]);
        $this->getJson('/api/checklists')->assertForbidden();
    }

    public function test_operadora_no_obtiene_acceso_implicito_al_cliente(): void
    {
        $operator = $this->operatorOrganization();
        $client = $this->organization('CHECK-CLIENT');
        $user = $this->platformConsultant($operator, []);

        $this->actingAsApiInOrganization($user, $client)
            ->getJson('/api/checklists')->assertForbidden();
    }

    public function test_error_de_auditoria_revierte_creacion(): void
    {
        $org = $this->organization('CHECK-ROLLBACK');
        $user = $this->user($org);
        $role = $this->role($org, 'checklist-rollback');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $this->grant($role->id, $org->id, Permission::ChecklistsManage);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failed'));
        $this->actingAsApiInOrganization($user, $org)->postJson('/api/checklists', ['name' => 'Rollback'])->assertStatus(500);
        $this->assertSame(0, DB::table('checklists')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checklist.created')->count());
    }

    public function test_limite_texto_y_atributos_protegidos_no_escriben_ni_auditan(): void
    {
        $org = $this->organization('CHECK-INPUT');
        $user = $this->user($org);
        $role = $this->role($org, 'checklist-input');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $this->grant($role->id, $org->id, Permission::ChecklistsManage);
        $this->actingAsApiInOrganization($user, $org);

        foreach (['id', 'status', 'created_at', 'updated_at'] as $field) {
            $this->postJson('/api/checklists', ['name' => 'N', $field => null])->assertUnprocessable();
        }
        $this->postJson('/api/checklists', ['name' => 'N', 'description' => str_repeat('é', 32768)])->assertUnprocessable();
        $this->assertSame(0, DB::table('checklists')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checklist.created')->count());

        $this->postJson('/api/checklists', ['name' => 'N', 'description' => str_repeat('é', 32767)])->assertCreated();
    }

    public function test_solo_lectura_no_puede_crear_ni_escribir_auditoria(): void
    {
        $org = $this->organization('CHECK-VIEW');
        $user = $this->user($org);
        $role = $this->role($org, 'checklist-view');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $this->grant($role->id, $org->id, Permission::ChecklistsView);
        $this->actingAsApiInOrganization($user, $org)->postJson('/api/checklists', ['name' => 'N'])->assertForbidden();
        $this->assertSame(0, DB::table('checklists')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checklist.created')->count());
    }

    private function grant(string $roleId, string $organizationId, Permission $permission): void
    {
        RolePermission::create([
            'id' => (string) Str::uuid7(),
            'role_id' => $roleId,
            'organization_id' => $organizationId,
            'permission' => $permission->value,
        ]);
    }
}
