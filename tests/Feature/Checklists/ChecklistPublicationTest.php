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

class ChecklistPublicationTest extends TestCase
{
    use RefreshDatabase, TenantFixtures;

    public function test_publicacion_exige_permiso_contenido_y_preserva_historia(): void
    {
        [$org, $user, $role, $checklist, $version] = $this->fixture();
        $url = "/api/checklists/{$checklist->id}/versions/{$version->id}";
        $this->patchJson($url, ['status' => 'published'])->assertUnauthorized();
        $this->actingAsApiInOrganization($user, $org)->patchJson($url, ['status' => 'published'])->assertForbidden();
        $this->grant($org->id, $role->id);
        $this->patchJson($url, ['status' => 'published'])->assertUnprocessable();
        $section = $this->postJson("/api/versions/{$version->id}/sections", ['name' => 'S'])->assertCreated()->json('data.id');
        $this->postJson("/api/sections/{$section}/questions", ['question_text' => 'Q', 'answer_type' => 'boolean'])->assertCreated();
        $this->patchJson($url, ['status' => 'published', 'published_at' => '2000-01-01'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'published'])->assertOk()->assertJsonPath('data.status', 'published');
        $publishedAt = DB::table('checklist_versions')->where('id', $version->id)->value('published_at');
        $this->assertNotNull($publishedAt);
        $this->postJson("/api/versions/{$version->id}/sections", ['name' => 'No'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertSame($publishedAt, DB::table('checklist_versions')->where('id', $version->id)->value('published_at'));
        $this->patchJson($url, ['status' => 'published'])->assertUnprocessable();
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'checklist_version.status_changed')->count());
    }

    public function test_fallo_de_auditoria_revierte_transicion(): void
    {
        $errorHandler = set_error_handler(static fn () => false);
        restore_error_handler();
        $exceptionHandler = set_exception_handler(static function () {});
        restore_exception_handler();
        try {
            [$org, $user, $role, $checklist, $version] = $this->fixture();
            $this->grant($org->id, $role->id);
            $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failed'));
            $this->actingAsApiInOrganization($user, $org)->patchJson("/api/checklists/{$checklist->id}/versions/{$version->id}", ['status' => 'archived'])->assertStatus(500);
            $this->assertSame('draft', DB::table('checklist_versions')->where('id', $version->id)->value('status'));
        } finally {
            $currentErrorHandler = set_error_handler(static fn () => false);
            restore_error_handler();
            if ($currentErrorHandler !== $errorHandler) {
                restore_error_handler();
            }
            $currentExceptionHandler = set_exception_handler(static function () {});
            restore_exception_handler();
            if ($currentExceptionHandler !== $exceptionHandler) {
                restore_exception_handler();
            }
        }
    }

    public function test_publicar_otra_version_archiva_la_anterior_con_auditoria(): void
    {
        [$org, $user, $role, $checklist, $version] = $this->fixture();
        $this->grant($org->id, $role->id);
        $old = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'created_by' => $user->id, 'version' => 'old', 'status' => 'published', 'published_at' => now()->subDay()]);
        $oldDate = DB::table('checklist_versions')->where('id', $old->id)->value('published_at');
        $this->actingAsApiInOrganization($user, $org);
        $section = $this->postJson("/api/versions/{$version->id}/sections", ['name' => 'S'])->assertCreated()->json('data.id');
        $this->postJson("/api/sections/{$section}/questions", ['question_text' => 'Q', 'answer_type' => 'text'])->assertCreated();
        $this->patchJson("/api/checklists/{$checklist->id}/versions/{$version->id}", ['status' => 'published'])->assertOk();
        $this->assertDatabaseHas('checklist_versions', ['id' => $old->id, 'status' => 'archived', 'published_at' => $oldDate]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'checklist_version.status_changed', 'auditable_id' => $old->id, 'organization_id' => $org->id]);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'checklist_version.status_changed')->count());
    }

    public function test_no_se_puede_publicar_borrador_historico_ni_acceder_a_otro_tenant(): void
    {
        [$org, $user, $role, $checklist, $version] = $this->fixture();
        $this->grant($org->id, $role->id);
        $other = $this->organization('PUB-OTHER');
        $foreign = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $other->id, 'name' => 'X', 'status' => true]);
        $version->update(['published_at' => now()]);
        $this->actingAsApiInOrganization($user, $org);
        $this->patchJson("/api/checklists/{$foreign->id}/versions/{$version->id}", ['status' => 'published'])->assertNotFound();
        $this->patchJson("/api/checklists/{$checklist->id}/versions/{$version->id}", ['status' => 'published'])->assertUnprocessable();
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checklist_version.status_changed')->count());
    }

    private function fixture(): array
    {
        $org = $this->organization('PUB');
        $user = $this->user($org);
        $role = $this->role($org, 'publisher');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'C', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'created_by' => $user->id, 'version' => '1', 'status' => 'draft']);

        return [$org, $user, $role, $checklist, $version];
    }

    private function grant(string $organizationId, string $roleId): void
    {
        RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $roleId, 'organization_id' => $organizationId, 'permission' => Permission::ChecklistsManage->value]);
    }
}
