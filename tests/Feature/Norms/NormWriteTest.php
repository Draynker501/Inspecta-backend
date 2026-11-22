<?php

namespace Tests\Feature\Norms;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\Question;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class NormWriteTest extends TestCase
{
    use RefreshDatabase, TenantFixtures;

    public function test_creacion_exige_permiso_y_audita_en_tenant(): void
    {
        $org = $this->organization('NORM-WRITE');
        $user = $this->user($org);
        $this->actingAsApiInOrganization($user, $org);
        $this->postJson('/api/norms', ['code' => 'N1', 'name' => 'Norma'])->assertForbidden();

        $role = $this->role($org, 'norm-manager');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $org->id, 'permission' => Permission::NormsManage->value]);
        $response = $this->postJson('/api/norms', ['code' => 'N1', 'name' => 'Norma'])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'auditable_id' => $response->json('data.id'), 'action' => 'norm.created']);
    }

    public function test_historial_archivado_y_borrador_nunca_publicado(): void
    {
        [$org, $user, $version, $question, $norm, $requirement] = $this->fixture();
        $this->actingAsApiInOrganization($user, $org);
        $url = "/api/questions/{$question->id}/norm-requirements";
        $link = $this->postJson($url, ['norm_requirement_id' => $requirement->id])->assertCreated()->json('data.link_id');
        $this->postJson($url, ['norm_requirement_id' => $requirement->id])->assertUnprocessable();
        $version->update(['status' => 'archived']);
        $this->putJson("/api/requirements/{$requirement->id}", ['description' => 'Antes'])->assertOk();
        $this->putJson("/api/norms/{$norm->id}", ['code' => 'N', 'name' => 'Antes'])->assertOk();
        $version->update(['published_at' => now()]);
        $this->putJson("/api/requirements/{$requirement->id}", ['description' => 'Después'])->assertUnprocessable();
        $this->putJson("/api/norms/{$norm->id}", ['code' => 'N', 'name' => 'Después'])->assertUnprocessable();
        $this->deleteJson("$url/$link")->assertUnprocessable();
        $this->patchJson("/api/norms/{$norm->id}", ['status' => false])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'norm.status_changed', 'auditable_id' => $norm->id]);
    }

    public function test_vinculos_permisos_inactivas_y_aislamiento(): void
    {
        [$org, $user, $version, $question, $norm, $requirement] = $this->fixture();
        $other = $this->organization('NORM-OTHER');
        $foreignNorm = Norm::create(['id' => (string) Str::uuid7(), 'organization_id' => $other->id, 'code' => 'X', 'name' => 'X', 'status' => true]);
        $foreign = NormRequirement::create(['id' => (string) Str::uuid7(), 'organization_id' => $other->id, 'norm_id' => $foreignNorm->id, 'code' => 'X', 'description' => 'X']);
        $this->actingAsApiInOrganization($user, $org);
        $url = "/api/questions/{$question->id}/norm-requirements";
        $this->postJson($url, ['norm_requirement_id' => $foreign->id])->assertNotFound();
        $norm->update(['status' => false]);
        $this->postJson($url, ['norm_requirement_id' => $requirement->id])->assertUnprocessable();
        $norm->update(['status' => true]);
        $id = $this->postJson($url, ['norm_requirement_id' => $requirement->id])->assertCreated()->json('data.link_id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.link_id', $id)->assertJsonMissingPath('data.0.organization_id');
        $this->deleteJson("$url/$id")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'question_norm_requirement.deleted', 'auditable_id' => $id]);
        $version->update(['published_at' => now()]);
        $this->postJson($url, ['norm_requirement_id' => $requirement->id])->assertUnprocessable();
    }

    public function test_fallo_auditoria_revierte_alta(): void
    {
        [$org, $user] = $this->fixture();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failed'));
        $this->actingAsApiInOrganization($user, $org)->postJson('/api/norms', ['code' => 'ROLLBACK', 'name' => 'N'])->assertStatus(500);
        $this->assertDatabaseMissing('norms', ['code' => 'ROLLBACK', 'organization_id' => $org->id]);
    }

    public function test_permisos_independientes_para_vincular_y_desvincular(): void
    {
        [$org, $user, , $question, , $requirement] = $this->fixture();
        $url = "/api/questions/{$question->id}/norm-requirements";
        $this->actingAsApiInOrganization($user, $org);
        $id = $this->postJson($url, ['norm_requirement_id' => $requirement->id])->assertCreated()->json('data.link_id');
        RolePermission::where('organization_id', $org->id)->where('permission', Permission::NormsView->value)->delete();
        $this->postJson($url, ['norm_requirement_id' => $requirement->id])->assertForbidden();
        $this->getJson($url)->assertForbidden();
        $this->deleteJson("$url/$id")->assertNoContent();
        RolePermission::where('organization_id', $org->id)->where('permission', Permission::ChecklistsView->value)->delete();
        $this->getJson($url)->assertForbidden();
    }

    public function test_requisitos_validan_campos_y_auditan_creacion(): void
    {
        [$org, $user, , , $norm] = $this->fixture();
        $this->actingAsApiInOrganization($user, $org);
        $url = "/api/norms/{$norm->id}/requirements";
        $this->postJson($url, ['code' => 'R2', 'description' => 'D', 'organization_id' => $org->id])->assertUnprocessable();
        $id = $this->postJson($url, ['code' => 'R2', 'description' => 'D'])->assertCreated()->json('data.id');
        $this->postJson($url, ['code' => 'R2', 'description' => 'D'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'norm_requirement.created', 'auditable_id' => $id]);
        $this->putJson("/api/requirements/$id", ['description' => str_repeat('é', 32768)])->assertUnprocessable();
    }

    public function test_fallo_auditoria_revierte_vinculacion(): void
    {
        [$org, $user, , $question, , $requirement] = $this->fixture();
        $this->actingAsApiInOrganization($user, $org);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failed'));
        $this->postJson("/api/questions/{$question->id}/norm-requirements", ['norm_requirement_id' => $requirement->id])->assertStatus(500);
        $this->assertDatabaseMissing('question_norm_requirement', ['question_id' => $question->id, 'norm_requirement_id' => $requirement->id]);
    }

    private function fixture(): array
    {
        $org = $this->organization('NORM-FIXTURE');
        $user = $this->user($org);
        $role = $this->role($org, 'norm-writer');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        foreach ([Permission::NormsManage, Permission::NormsView, Permission::ChecklistsManage, Permission::ChecklistsView] as $permission) {
            RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $org->id, 'permission' => $permission->value]);
        }
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'C', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'version' => '1', 'status' => 'draft', 'created_by' => $user->id]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1]);
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Q', 'answer_type' => 'text', 'display_order' => 1, 'required' => true, 'allows_evidence' => false, 'status' => true]);
        $norm = Norm::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'code' => 'N', 'name' => 'Norma', 'status' => true]);
        $requirement = NormRequirement::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'norm_id' => $norm->id, 'code' => 'R', 'description' => 'Requisito']);

        return [$org, $user, $version, $question, $norm, $requirement];
    }
}
