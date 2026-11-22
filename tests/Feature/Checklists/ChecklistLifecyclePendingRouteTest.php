<?php

namespace Tests\Feature\Checklists;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistVersion;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ChecklistLifecyclePendingRouteTest extends TestCase
{
    use RefreshDatabase, TenantFixtures;

    public function test_publicacion_rechaza_estructura_con_tenant_inconsistente(): void
    {
        [$org, $user, $checklist, $version] = $this->fixture();
        $other = $this->organization('LIFE-STRUCTURE-OTHER');
        $section = (string) Str::uuid7();
        $question = (string) Str::uuid7();
        DB::table('checklist_sections')->insert(['id' => $section, 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1]);
        DB::table('questions')->insert(['id' => $question, 'organization_id' => $org->id, 'checklist_section_id' => $section, 'question_text' => 'Q', 'answer_type' => 'text', 'display_order' => 1]);
        DB::table('questions')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section, 'question_text' => 'Otra', 'answer_type' => 'text', 'display_order' => 2]);
        $url = "/api/checklists/{$checklist->id}/versions/{$version->id}";
        $this->actingAsApiInOrganization($user, $org);

        DB::table('questions')->where('id', $question)->update(['organization_id' => $other->id]);
        $this->patchJson($url, ['status' => 'published'])->assertUnprocessable();
        DB::table('questions')->where('id', $question)->update(['organization_id' => $org->id]);
        $foreignSection = (string) Str::uuid7();
        DB::table('checklist_sections')->insert(['id' => $foreignSection, 'organization_id' => $other->id, 'checklist_version_id' => $version->id, 'name' => 'Ajena', 'display_order' => 2]);
        $this->patchJson($url, ['status' => 'published'])->assertUnprocessable();
        $this->assertDatabaseHas('checklist_versions', ['id' => $version->id, 'status' => 'draft', 'published_at' => null]);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checklist_version.status_changed')->count());
    }

    public function test_publicacion_valida_preguntas_y_normas_sin_exigir_vinculos(): void
    {
        [$org, $user, $checklist, $version] = $this->fixture();
        $this->actingAsApiInOrganization($user, $org);
        $section = (string) Str::uuid7();
        $question = (string) Str::uuid7();
        DB::table('checklist_sections')->insert(['id' => $section, 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1]);
        DB::table('questions')->insert(['id' => $question, 'organization_id' => $org->id, 'checklist_section_id' => $section, 'question_text' => 'Q', 'answer_type' => 'text', 'display_order' => 1]);
        $url = "/api/checklists/{$checklist->id}/versions/{$version->id}";
        DB::table('questions')->where('id', $question)->update(['answer_type' => 'legacy']);
        $this->patchJson($url, ['status' => 'published'])->assertUnprocessable();
        DB::table('questions')->where('id', $question)->update(['answer_type' => 'text']);
        $norm = (string) Str::uuid7();
        $requirement = (string) Str::uuid7();
        DB::table('norms')->insert(['id' => $norm, 'organization_id' => $org->id, 'code' => 'N', 'name' => 'Norm', 'status' => false]);
        DB::table('norm_requirements')->insert(['id' => $requirement, 'organization_id' => $org->id, 'norm_id' => $norm, 'code' => 'R', 'description' => 'R']);
        DB::table('question_norm_requirement')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'question_id' => $question, 'norm_requirement_id' => $requirement]);
        $this->patchJson($url, ['status' => 'published'])->assertUnprocessable();
        DB::table('norms')->where('id', $norm)->update(['status' => true]);
        DB::table('questions')->where('id', $question)->update(['answer_type' => 'multiple_choice', 'options' => json_encode([['value' => 'x', 'label' => 'X']])]);
        $this->patchJson($url, ['status' => 'published'])->assertUnprocessable();
        DB::table('questions')->where('id', $question)->update(['answer_type' => 'text', 'options' => null]);
        $this->patchJson($url, ['status' => 'published'])->assertOk();
        $second = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'created_by' => $user->id, 'version' => '2', 'status' => 'draft']);
        $section2 = (string) Str::uuid7();
        DB::table('checklist_sections')->insert(['id' => $section2, 'organization_id' => $org->id, 'checklist_version_id' => $second->id, 'name' => 'S2', 'display_order' => 1]);
        DB::table('questions')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section2, 'question_text' => 'Q', 'answer_type' => 'text', 'display_order' => 1]);
        $this->patchJson("/api/checklists/{$checklist->id}/versions/{$second->id}", ['status' => 'published'])->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_edicion_historial_y_acceso_por_permiso(): void
    {
        [$org, $user, $checklist, $version] = $this->fixture();
        $this->actingAsApiInOrganization($user, $org);
        $this->putJson("/api/checklists/{$checklist->id}", ['name' => 'Nuevo'])->assertOk()->assertJsonPath('data.name', 'Nuevo');
        $this->putJson("/api/checklists/{$checklist->id}/versions/{$version->id}", ['version' => '2'])->assertOk()->assertJsonPath('data.version', '2');
        $version->update(['published_at' => now()]);
        $this->putJson("/api/checklists/{$checklist->id}/versions/{$version->id}", ['version' => '3'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'checklist_version.updated', 'auditable_id' => $version->id]);
    }

    public function test_fallo_auditoria_revierte_edicion_de_version(): void
    {
        [$org, $user, $checklist, $version] = $this->fixture();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failed'));
        $this->actingAsApiInOrganization($user, $org)
            ->putJson("/api/checklists/{$checklist->id}/versions/{$version->id}", ['version' => '2'])->assertStatus(500);
        $this->assertDatabaseHas('checklist_versions', ['id' => $version->id, 'version' => '1']);
    }

    public function test_version_activa_requiere_solo_lectura_de_checklist_y_aisla_vinculos(): void
    {
        [$org, $user, $checklist, $version, $role] = $this->fixture();
        RolePermission::where('role_id', $role->id)->where('permission', Permission::ChecklistsManage->value)->delete();
        $version->update(['status' => 'published', 'published_at' => now()]);
        $section = (string) Str::uuid7();
        DB::table('checklist_sections')->insert(['id' => $section, 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1]);
        $question = (string) Str::uuid7();
        DB::table('questions')->insert(['id' => $question, 'organization_id' => $org->id, 'checklist_section_id' => $section, 'question_text' => 'Q', 'answer_type' => 'text', 'display_order' => 1]);
        $other = $this->organization('LIFE-OTHER');
        $norm = (string) Str::uuid7();
        $foreignNorm = (string) Str::uuid7();
        $requirement = (string) Str::uuid7();
        $foreignRequirement = (string) Str::uuid7();
        $wrongPivotRequirement = (string) Str::uuid7();
        $link = (string) Str::uuid7();
        DB::table('norms')->insert([
            ['id' => $norm, 'organization_id' => $org->id, 'code' => 'N', 'name' => 'Local'],
            ['id' => $foreignNorm, 'organization_id' => $other->id, 'code' => 'F', 'name' => 'Ajena'],
        ]);
        DB::table('norm_requirements')->insert([
            ['id' => $requirement, 'organization_id' => $org->id, 'norm_id' => $norm, 'code' => 'R', 'description' => 'Requisito'],
            ['id' => $foreignRequirement, 'organization_id' => $other->id, 'norm_id' => $foreignNorm, 'code' => 'F', 'description' => 'Secreto'],
            ['id' => $wrongPivotRequirement, 'organization_id' => $org->id, 'norm_id' => $norm, 'code' => 'P', 'description' => 'Vínculo ajeno'],
        ]);
        // Defensa en profundidad: simular vínculos heredados imposibles tras
        // DEC-17, sin desactivar permanentemente la integridad en pruebas.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        } else {
            DB::statement('SET SESSION foreign_key_checks = 0');
        }
        $invalidIds = [(string) Str::uuid7(), (string) Str::uuid7()];
        try {
            DB::table('question_norm_requirement')->insert([
                ['id' => $link, 'organization_id' => $org->id, 'question_id' => $question, 'norm_requirement_id' => $requirement],
                ['id' => $invalidIds[0], 'organization_id' => $org->id, 'question_id' => $question, 'norm_requirement_id' => $foreignRequirement],
                ['id' => $invalidIds[1], 'organization_id' => $other->id, 'question_id' => $question, 'norm_requirement_id' => $wrongPivotRequirement],
            ]);
        } finally {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('SET SESSION foreign_key_checks = 1');
            }
        }
        try {
            $this->actingAsApiInOrganization($user, $org);
            $url = "/api/checklists/{$checklist->id}/active-version";
            $this->getJson($url)->assertForbidden();
            RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $org->id, 'permission' => Permission::ChecklistsView->value]);
            $response = $this->getJson($url)->assertOk()->assertJsonPath('data.active_version.sections.0.questions.0.question_text', 'Q');
            // Opción B: sin `norms.view` la normativa embebida no se expone.
            $this->assertSame([], $response->json('data.active_version.sections.0.questions.0.norm_requirements'));
            $this->getJson('/api/norms')->assertForbidden();
            // Con `norms.view` solo se exponen los vínculos de la organización.
            RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $org->id, 'permission' => Permission::NormsView->value]);
            $this->assertSame([[
                'link_id' => $link, 'id' => $requirement, 'code' => 'R', 'description' => 'Requisito',
            ]], $this->getJson($url)->assertOk()->json('data.active_version.sections.0.questions.0.norm_requirements'));
            $foreignChecklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $other->id, 'name' => 'Ajeno', 'status' => true]);
            $this->getJson("/api/checklists/{$foreignChecklist->id}/active-version")->assertNotFound();
        } finally {
            DB::table('question_norm_requirement')->whereIn('id', $invalidIds)->delete();
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA defer_foreign_keys = OFF');
            }
        }
    }

    private function fixture(): array
    {
        $org = $this->organization('LIFE');
        $user = $this->user($org);
        $role = $this->role($org, 'editor');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $org->id, 'permission' => Permission::ChecklistsManage->value]);
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'C', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'created_by' => $user->id, 'version' => '1', 'status' => 'draft']);

        return [$org, $user, $checklist, $version, $role];
    }
}
