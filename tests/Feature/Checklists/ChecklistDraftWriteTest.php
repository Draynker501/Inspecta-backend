<?php

namespace Tests\Feature\Checklists;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ChecklistDraftWriteTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_version_draft_creada_con_permiso_y_auditoria_en_ruta_real(): void
    {
        $org = $this->organization('VERSION-A');
        $other = $this->organization('VERSION-B');
        $user = $this->user($org);
        $role = $this->role($org, 'version-writer');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $checklist = $this->checklist($org->id);
        $foreign = $this->checklist($other->id);
        $this->postJson("/api/checklists/{$checklist->id}/versions", [])->assertUnauthorized();
        $this->actingAsApiInOrganization($user, $org);
        $this->postJson("/api/checklists/{$foreign->id}/versions", [])->assertForbidden();
        $this->grant($role->id, $org->id);
        $this->postJson("/api/checklists/{$foreign->id}/versions", ['version' => '1'])->assertNotFound();
        $this->postJson("/api/checklists/{$checklist->id}/versions", ['version' => '1', 'status' => 'published'])->assertUnprocessable();
        $response = $this->postJson("/api/checklists/{$checklist->id}/versions", ['version' => '1'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.created_by', $user->id)->assertJsonPath('data.published_at', null);
        $this->postJson("/api/checklists/{$checklist->id}/versions", ['version' => '1'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'user_id' => $user->id, 'action' => 'checklist_version.created', 'auditable_id' => $response->json('data.id')]);
        $this->assertSame(1, DB::table('checklist_versions')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checklist_version.created')->count());
    }

    public function test_creacion_de_version_y_seccion_es_tenant_scoped_y_auditada(): void
    {
        $org = $this->organization('DRAFT-A');
        $other = $this->organization('DRAFT-B');
        $user = $this->user($org);
        $role = $this->role($org, 'draft-manager');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $checklist = $this->checklist($org->id);
        $foreign = $this->checklist($other->id);
        $this->actingAsApiInOrganization($user, $org);
        $this->postJson("/api/checklists/{$foreign->id}/versions", [])->assertForbidden();
        $this->postJson('/api/versions/missing/sections', [])->assertForbidden();
        $this->grant($role->id, $org->id);
        $this->postJson("/api/checklists/{$foreign->id}/versions", ['version' => '1'])->assertNotFound();
        $this->postJson("/api/checklists/{$checklist->id}/versions", ['version' => '1', 'status' => 'published'])->assertUnprocessable();
        $response = $this->postJson("/api/checklists/{$checklist->id}/versions", ['version' => '1'])->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.organization_id', $org->id)
            ->assertJsonPath('data.created_by', $user->id)->assertJsonPath('data.published_at', null);
        $id = $response->json('data.id');
        $this->postJson("/api/checklists/{$checklist->id}/versions", ['version' => '1'])->assertUnprocessable();
        $this->postJson("/api/versions/{$id}/sections", ['name' => 'Primera'])->assertCreated()->assertJsonPath('data.display_order', 1);
        $this->postJson("/api/versions/{$id}/sections", ['name' => 'Segunda'])->assertCreated()->assertJsonPath('data.display_order', 2);
        $this->postJson("/api/versions/{$id}/sections", ['name' => 'Duplicada', 'display_order' => 1])->assertUnprocessable();
        $this->postJson("/api/versions/{$id}/sections", ['name' => 'Baja', 'display_order' => 0])->assertUnprocessable();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checklist_version.created')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'checklist_section.created')->count());
        $this->assertSame(2, DB::table('checklist_sections')->count());
    }

    public function test_seccion_solo_en_borrador_nunca_publicado(): void
    {
        $org = $this->organization('DRAFT-STATE');
        $user = $this->user($org);
        $role = $this->role($org, 'draft-state');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $this->grant($role->id, $org->id);
        $checklist = $this->checklist($org->id);
        $this->actingAsApiInOrganization($user, $org);

        foreach ([['published', now()], ['archived', now()], ['draft', now()]] as $index => [$status, $publishedAt]) {
            $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'created_by' => $user->id, 'version' => 'historica-'.$index, 'status' => $status, 'published_at' => $publishedAt]);
            $this->postJson("/api/versions/{$version->id}/sections", ['name' => 'No'])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('checklist_sections')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checklist_section.created')->count());
    }

    public function test_fallo_auditoria_revierte_version(): void
    {
        $org = $this->organization('DRAFT-ROLLBACK');
        $user = $this->user($org);
        $role = $this->role($org, 'draft-rollback');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $this->grant($role->id, $org->id);
        $checklist = $this->checklist($org->id);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failed'));
        $this->actingAsApiInOrganization($user, $org)->postJson("/api/checklists/{$checklist->id}/versions", ['version' => '1'])->assertStatus(500);
        $this->assertSame(0, DB::table('checklist_versions')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checklist_version.created')->count());
    }

    public function test_conflicto_unico_de_version_es_422(): void
    {
        $org = $this->organization('DRAFT-RACE');
        $user = $this->user($org);
        $role = $this->role($org, 'draft-race');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $this->grant($role->id, $org->id);
        $checklist = $this->checklist($org->id);
        $this->actingAsApiInOrganization($user, $org);

        ChecklistVersion::creating(function (): void {
            $exception = new PDOException('unique');
            $exception->errorInfo = ['23000', 19, 'UNIQUE constraint failed: checklist_versions.checklist_id, checklist_versions.version'];
            throw new QueryException('sqlite', 'insert', [], $exception);
        });
        $this->postJson("/api/checklists/{$checklist->id}/versions", ['version' => '1'])->assertUnprocessable();
        $this->assertSame(0, DB::table('checklist_versions')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checklist_version.created')->count());
    }

    public function test_otro_error_sql_de_version_no_se_oculta(): void
    {
        $org = $this->organization('DRAFT-SQL');
        $user = $this->user($org);
        $role = $this->role($org, 'draft-sql');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $this->grant($role->id, $org->id);
        $checklist = $this->checklist($org->id);
        ChecklistVersion::creating(function (): void {
            $exception = new PDOException('other');
            $exception->errorInfo = ['23000', 19, 'UNIQUE constraint failed: some_other_table.id'];
            throw new QueryException('sqlite', 'insert', [], $exception);
        });
        $this->actingAsApiInOrganization($user, $org)->postJson("/api/checklists/{$checklist->id}/versions", ['version' => '1'])->assertStatus(500);
        $this->assertSame(0, DB::table('checklist_versions')->count());
    }

    public function test_conflicto_unico_de_seccion_es_422(): void
    {
        $org = $this->organization('SECTION-RACE');
        $user = $this->user($org);
        $role = $this->role($org, 'section-race');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        $this->grant($role->id, $org->id);
        $checklist = $this->checklist($org->id);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'created_by' => $user->id, 'version' => '1', 'status' => 'draft']);
        ChecklistSection::creating(function (): void {
            $exception = new PDOException('unique');
            $exception->errorInfo = ['23000', 19, 'UNIQUE constraint failed: checklist_sections.checklist_version_id, checklist_sections.display_order'];
            throw new QueryException('sqlite', 'insert', [], $exception);
        });
        $this->actingAsApiInOrganization($user, $org)->postJson("/api/versions/{$version->id}/sections", ['name' => 'S'])->assertUnprocessable();
        $this->assertSame(0, DB::table('checklist_sections')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checklist_section.created')->count());
    }

    private function checklist(string $organizationId): Checklist
    {
        return Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $organizationId, 'name' => 'Checklist', 'status' => true]);
    }

    private function grant(string $roleId, string $organizationId): void
    {
        RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $roleId, 'organization_id' => $organizationId, 'permission' => Permission::ChecklistsManage->value]);
    }
}
