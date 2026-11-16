<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Question;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class InspectionHttpTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_sqlite_de_pruebas_incluye_migracion_dec16_y_fk_activas(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Verificación específica de SQLite en memoria.');
        }
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->assertDatabaseHas('migrations', ['migration' => '2026_11_18_000001_enforce_inspection_location_tenant_foreign_keys']);
        $foreignKeys = collect(DB::select('PRAGMA foreign_key_list("inspections")'));
        $this->assertTrue($foreignKeys->contains(fn ($fk) => $fk->table === 'areas' && $fk->from === 'plant_id' && $fk->to === 'plant_id'));
        $this->assertTrue($foreignKeys->contains(fn ($fk) => $fk->table === 'production_lines' && $fk->from === 'area_id' && $fk->to === 'area_id'));
    }

    public function test_frontera_y_validacion_de_lectura(): void
    {
        $org = $this->organization('INS-HTTP');
        $user = $this->user($org);
        $this->getJson('/api/inspections')->assertUnauthorized();
        $this->actingAsApiInOrganization($user, $org);
        $this->getJson('/api/inspections')->assertForbidden();
        $role = $this->role($org, 'ins-http');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        RolePermission::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'role_id' => $role->id, 'permission' => Permission::InspectionsExecute->value,
        ]);
        $this->getJson('/api/inspections?per_page=0')->assertUnprocessable();
        $this->getJson('/api/inspections?unknown=1')->assertUnprocessable();
        $this->getJson('/api/inspections')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/inspections/'.Str::uuid7())->assertNotFound();
    }

    public function test_creacion_propia_con_auditoria_minimizada(): void
    {
        [$org, $user, $plant, $area, $version] = $this->inspectionFixture();
        $this->actingAsApiInOrganization($user, $org);
        $response = $this->postJson('/api/inspections', [
            'checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id,
            'latitude' => 12.1234567, 'longitude' => -75.1234567, 'observations' => 'privado',
        ])->assertCreated()->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.supervisor_id', null);
        $this->assertSame('7', $response->json('data.id')[14]);
        $this->assertNotNull($response->json('data.started_at'));
        $this->assertNull($response->json('data.completed_at'));
        $this->assertNull($response->json('data.supervisor_confirmed_at'));
        $this->assertNull($response->json('data.rejected_at'));
        $audit = DB::table('audit_logs')->where('action', 'inspection.created')->sole();
        $this->assertStringNotContainsString('privado', $audit->new_values);
        $this->assertStringNotContainsString('latitude', $audit->new_values);
    }

    public function test_entrada_cerrada_pareja_de_coordenadas_y_limites(): void
    {
        [$org, $user, $plant, $area, $version] = $this->inspectionFixture();
        $this->actingAsApiInOrganization($user, $org);
        $base = ['checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id];
        foreach (['id', 'organization_id', 'user_id', 'supervisor_id', 'status', 'started_at', 'answers', 'extra'] as $field) {
            $this->postJson('/api/inspections', $base + [$field => null])->assertUnprocessable();
        }
        foreach ([
            ['latitude' => 1], ['latitude' => 91, 'longitude' => 1],
            ['latitude' => 1, 'longitude' => -181], ['latitude' => 1.12345678, 'longitude' => 1],
            ['latitude' => 0.0000000000001, 'longitude' => 1],
            ['latitude' => true, 'longitude' => 1], ['observations' => str_repeat('é', 32768)],
            ['location_description' => str_repeat('a', 256)], ['production_line_id' => 'invalid'],
        ] as $invalid) {
            $this->postJson('/api/inspections', $base + $invalid)->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('inspections')->count());
        $this->postJson('/api/inspections', $base + ['latitude' => 90, 'longitude' => -180, 'observations' => str_repeat('é', 32767)])
            ->assertCreated();
    }

    public function test_lectura_filtra_propiedad_y_asignacion_antes_de_paginar_y_mantiene_historia(): void
    {
        [$org, $user, $plant, $area, $version, $role, $checklist] = $this->inspectionFixture();
        $other = $this->user($org);
        $sibling = $this->area($plant, 'B');
        $line = $this->productionLine($area, 'HISTORY');
        $this->actingAsApiInOrganization($user, $org);
        $base = ['checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id];
        $first = $this->postJson('/api/inspections', $base)->assertCreated()->json('data.id');
        $this->postJson('/api/inspections', array_replace($base, ['area_id' => $sibling->id]))->assertNotFound();
        DB::table('user_plants')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'user_id' => $user->id, 'plant_id' => $plant->id]);
        $hiddenSibling = $this->postJson('/api/inspections', array_replace($base, ['area_id' => $sibling->id]))->assertCreated()->json('data.id');
        DB::table('user_plants')->where('organization_id', $org->id)->where('user_id', $user->id)->delete();
        $second = $this->postJson('/api/inspections', $base + ['production_line_id' => $line->id])->assertCreated()->json('data.id');
        DB::table('inspections')->where('id', $second)->update(['user_id' => $other->id]);
        $this->getJson('/api/inspections?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $first);
        $hidden = $this->getJson('/api/inspections/'.$second)->assertNotFound();
        $missing = $this->getJson('/api/inspections/'.Str::uuid7())->assertNotFound();
        $this->assertSame($missing->json(), $hidden->json());
        RolePermission::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'role_id' => $role->id, 'permission' => Permission::InspectionsManage->value]);
        $this->getJson('/api/inspections?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $second);
        $this->getJson('/api/inspections/'.$hiddenSibling)->assertNotFound();
        $this->getJson('/api/inspections?status=completed')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/inspections?status=invalid')->assertUnprocessable();
        DB::table('areas')->where('id', $area->id)->update(['status' => false, 'deleted_at' => now()]);
        DB::table('plants')->where('id', $plant->id)->update(['status' => false, 'deleted_at' => now()]);
        DB::table('production_lines')->where('id', $line->id)->update(['status' => false, 'deleted_at' => now()]);
        DB::table('checklists')->where('id', $checklist->id)->update(['status' => false]);
        DB::table('checklist_versions')->where('id', $version->id)->update(['status' => 'archived']);
        $this->getJson('/api/inspections/'.$second)->assertOk()->assertJsonPath('data.id', $second)
            ->assertJsonPath('data.production_line_id', $line->id)->assertJsonPath('data.area_id', $area->id)
            ->assertJsonMissingPath('data.answers')->assertJsonMissingPath('data.checklist');
    }

    public function test_recursos_y_asignacion_son_obligatorios_sin_view_all(): void
    {
        [$org, $user, $plant, $area, $version, $role, $checklist] = $this->inspectionFixture();
        $sibling = $this->area($plant, 'B');
        $line = $this->productionLine($sibling, 'L');
        RolePermission::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'role_id' => $role->id, 'permission' => Permission::LocationsViewAll->value]);
        $this->actingAsApiInOrganization($user, $org);
        $base = ['checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id];
        $this->postJson('/api/inspections', array_replace($base, ['area_id' => $sibling->id]))->assertNotFound();
        $this->postJson('/api/inspections', $base + ['production_line_id' => $line->id])->assertNotFound();
        DB::table('user_plants')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'user_id' => $user->id, 'plant_id' => $plant->id]);
        $this->postJson('/api/inspections', array_replace($base, ['area_id' => $sibling->id]))->assertCreated();
        DB::table('checklists')->where('id', $checklist->id)->update(['status' => false]);
        $this->postJson('/api/inspections', $base)->assertUnprocessable();
        DB::table('checklists')->where('id', $checklist->id)->update(['status' => true]);
        DB::table('questions')->update(['status' => false]);
        $this->postJson('/api/inspections', $base)->assertUnprocessable();
        DB::table('questions')->update(['status' => true]);
        DB::table('checklist_versions')->where('id', $version->id)->update(['status' => 'archived']);
        $this->postJson('/api/inspections', $base)->assertUnprocessable();
        DB::table('checklist_versions')->where('id', $version->id)->update(['status' => 'published']);
        foreach (['plants' => $plant->id, 'areas' => $area->id] as $table => $id) {
            DB::table($table)->where('id', $id)->update(['status' => false]);
            $this->postJson('/api/inspections', $base)->assertUnprocessable();
            DB::table($table)->where('id', $id)->update(['status' => true]);
        }
        $localLine = $this->productionLine($area, 'LOCAL');
        DB::table('production_lines')->where('id', $localLine->id)->update(['status' => false]);
        $this->postJson('/api/inspections', $base + ['production_line_id' => $localLine->id])->assertUnprocessable();
        DB::table('production_lines')->where('id', $localLine->id)->update(['status' => true, 'deleted_at' => now()]);
        $this->postJson('/api/inspections', $base + ['production_line_id' => $localLine->id])->assertNotFound();
        DB::table('areas')->where('id', $area->id)->update(['deleted_at' => now()]);
        $this->postJson('/api/inspections', $base)->assertNotFound();
    }

    public function test_auditoria_fallida_revierte_creacion(): void
    {
        [$org, $user, $plant, $area, $version] = $this->inspectionFixture();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit failed'));
        $this->actingAsApiInOrganization($user, $org)->postJson('/api/inspections', [
            'checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id,
        ])->assertStatus(500);
        $this->assertSame(0, DB::table('inspections')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.created')->count());
    }

    public function test_aislamiento_membresia_y_permisos_independientes(): void
    {
        [$org, $user, $plant, $area, $version, $role] = $this->inspectionFixture();
        $other = $this->organization('INS-FOREIGN');
        $otherPlant = $this->plant($other, 'FOREIGN');
        $otherArea = $this->area($otherPlant, 'FOREIGN');
        $base = ['checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id];
        $this->actingAsApiInOrganization($user, $org);
        $id = $this->postJson('/api/inspections', $base)->assertCreated()->json('data.id');
        $this->postJson('/api/inspections', array_replace($base, [
            'plant_id' => $otherPlant->id, 'area_id' => $otherArea->id,
        ]))->assertNotFound();
        $this->postJson('/api/inspections', array_replace($base, ['checklist_version_id' => (string) Str::uuid7()]))
            ->assertNotFound();
        DB::table('role_permissions')->where('role_id', $role->id)->where('permission', Permission::InspectionsExecute->value)
            ->update(['permission' => Permission::InspectionsManage->value]);
        $this->getJson('/api/inspections/'.$id)->assertOk();
        $this->postJson('/api/inspections', $base)->assertForbidden();
        DB::table('organization_memberships')->where('organization_id', $org->id)->where('user_id', $user->id)
            ->update(['status' => 'revoked']);
        $this->getJson('/api/inspections')->assertForbidden();
        $this->getJson('/api/inspections/'.$id)->assertForbidden();
    }

    public function test_operadora_sin_membresia_de_cliente_no_tiene_acceso(): void
    {
        $operator = $this->operatorOrganization();
        $client = $this->organization('INS-CLIENT');
        $user = $this->platformConsultant($operator, []);
        $this->actingAsApiInOrganization($user, $client);
        $this->getJson('/api/inspections')->assertForbidden();
        $this->postJson('/api/inspections', [])->assertForbidden();
    }

    public function test_norma_desactivada_despues_de_publicacion_no_impide_inspeccion_dec17(): void
    {
        [$org, $user, $plant, $area, $version, $role, $checklist] = $this->inspectionFixture();
        foreach ([Permission::ChecklistsManage, Permission::NormsManage] as $permission) {
            RolePermission::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
                'role_id' => $role->id, 'permission' => $permission->value]);
        }
        DB::table('checklist_versions')->where('id', $version->id)->update(['status' => 'draft', 'published_at' => null]);
        $question = DB::table('questions')->where('organization_id', $org->id)->value('id');
        $norm = (string) Str::uuid7();
        $requirement = (string) Str::uuid7();
        DB::table('norms')->insert(['id' => $norm, 'organization_id' => $org->id, 'code' => 'N', 'name' => 'Norma', 'status' => true]);
        DB::table('norm_requirements')->insert(['id' => $requirement, 'organization_id' => $org->id, 'norm_id' => $norm, 'code' => 'R', 'description' => 'R']);
        DB::table('question_norm_requirement')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'question_id' => $question, 'norm_requirement_id' => $requirement]);
        $this->actingAsApiInOrganization($user, $org);
        $this->patchJson("/api/checklists/{$checklist->id}/versions/{$version->id}", ['status' => 'published'])->assertOk();
        $this->patchJson("/api/norms/{$norm}", ['status' => false])->assertOk();
        $this->assertDatabaseHas('norms', ['id' => $norm, 'status' => false]);
        $this->postJson('/api/inspections', [
            'checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id,
        ])->assertCreated()->assertJsonPath('data.checklist_version_id', $version->id);
    }

    public function test_filtros_validos_invalidos_y_orden_se_aplican_antes_de_paginar(): void
    {
        [$org, $user, $plant, $area, $version] = $this->inspectionFixture();
        $this->actingAsApiInOrganization($user, $org);
        $base = ['checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id];
        $ids = [];
        foreach (['draft', 'completed', 'confirmed', 'rejected'] as $status) {
            $id = $this->postJson('/api/inspections', $base)->assertCreated()->json('data.id');
            DB::table('inspections')->where('id', $id)->update(['status' => $status, 'created_at' => '2026-01-01 00:00:00']);
            $ids[] = $id;
            $this->getJson('/api/inspections?status='.$status.'&plant_id='.$plant->id.'&area_id='.$area->id)
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        }
        rsort($ids);
        $this->getJson('/api/inspections?per_page=2&page=2')->assertOk()->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.id', $ids[2])->assertJsonPath('data.1.id', $ids[3]);
        foreach (['page=0', 'page=-1', 'page=abc', 'per_page=101', 'per_page=-1',
            'plant_id=bad', 'area_id=bad', 'status=unknown', 'unexpected=1'] as $query) {
            $this->getJson('/api/inspections?'.$query)->assertUnprocessable();
        }
    }

    public function test_coord_exactas_nullable_y_contenido_auditado(): void
    {
        [$org, $user, $plant, $area, $version] = $this->inspectionFixture();
        $this->actingAsApiInOrganization($user, $org);
        $base = ['checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id];
        $result = $this->postJson('/api/inspections', $base + [
            'latitude' => -0.0000001, 'longitude' => 179.1234567,
            'location_description' => 'texto privado', 'observations' => 'observaciones privadas',
        ])->assertCreated()->assertJsonPath('data.latitude', '-0.0000001')->assertJsonPath('data.longitude', '179.1234567');
        $id = $result->json('data.id');
        $this->getJson('/api/inspections/'.$id)->assertOk()->assertJsonPath('data.latitude', '-0.0000001')
            ->assertJsonPath('data.longitude', '179.1234567');
        $audit = DB::table('audit_logs')->where('action', 'inspection.created')->where('auditable_id', $id)->sole();
        $payload = json_decode($audit->new_values, true, flags: JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(['checklist_version_id', 'plant_id', 'area_id', 'production_line_id', 'status'], array_keys($payload));
        $this->assertSame($user->id, $audit->user_id);
        $this->postJson('/api/inspections', $base + ['latitude' => null, 'longitude' => null, 'production_line_id' => null])
            ->assertCreated()->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null);
        foreach (['0.00000001', '-0.00000001', '90.0000001'] as $latitude) {
            $this->postJson('/api/inspections', $base + ['latitude' => $latitude, 'longitude' => 0])->assertUnprocessable();
        }
    }

    public function test_manage_y_view_all_no_eliminan_asignacion_ni_aislamiento(): void
    {
        [$org, $user, $plant, $area, $version, $role] = $this->inspectionFixture();
        foreach ([Permission::InspectionsManage, Permission::LocationsViewAll] as $permission) {
            RolePermission::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
                'role_id' => $role->id, 'permission' => $permission->value]);
        }
        $this->actingAsApiInOrganization($user, $org);
        $base = ['checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id];
        $local = $this->postJson('/api/inspections', $base)->assertCreated()->json('data.id');
        $other = $this->organization('INS-ISOLATION');
        $otherUser = $this->user($other);
        $otherPlant = $this->plant($other, 'P');
        $otherArea = $this->area($otherPlant, 'A');
        $otherChecklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $other->id, 'name' => 'C', 'status' => true]);
        $otherVersion = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $other->id,
            'checklist_id' => $otherChecklist->id, 'created_by' => $otherUser->id, 'version' => '1', 'status' => 'published']);
        $foreign = (string) Str::uuid7();
        DB::table('inspections')->insert(['id' => $foreign, 'organization_id' => $other->id, 'user_id' => $otherUser->id,
            'checklist_version_id' => $otherVersion->id, 'plant_id' => $otherPlant->id, 'area_id' => $otherArea->id, 'status' => 'draft']);
        $this->getJson('/api/inspections')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/inspections/'.$foreign)->assertNotFound();
        $this->postJson('/api/inspections', array_replace($base, ['checklist_version_id' => $otherVersion->id]))->assertNotFound();
        DB::table('user_areas')->where('organization_id', $org->id)->where('user_id', $user->id)->delete();
        $this->getJson('/api/inspections')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/inspections/'.$local)->assertNotFound();
        $this->postJson('/api/inspections', $base)->assertNotFound();
        DB::table('user_plants')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'user_id' => $user->id, 'plant_id' => $plant->id]);
        $this->getJson('/api/inspections/'.$local)->assertOk();
    }

    public function test_nombre_de_rol_no_concede_permiso_y_revalidacion_detecta_permiso_revocado(): void
    {
        [$org, $user, $plant, $area, $version, $role] = $this->inspectionFixture();
        $base = ['checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id];
        DB::table('roles')->where('id', $role->id)->update(['name' => 'Administrador']);
        $this->actingAsApiInOrganization($user, $org);
        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        $this->postJson('/api/inspections', $base)->assertForbidden();
        $this->getJson('/api/inspections')->assertForbidden();
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'role_id' => $role->id, 'permission' => Permission::InspectionsExecute->value]);
        $revoked = false;
        DB::listen(function ($event) use (&$revoked, $role): void {
            if (! $revoked && preg_match('/from\s+[`"]checklist_versions[`"]/i', $event->sql)) {
                $revoked = true;
                DB::table('role_permissions')->where('role_id', $role->id)->delete();
            }
        });
        $this->postJson('/api/inspections', $base)->assertForbidden();
        $this->assertTrue($revoked);
        $this->assertSame(0, DB::table('inspections')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.created')->count());
    }

    private function inspectionFixture(): array
    {
        $org = $this->organization('INS-TEST');
        $user = $this->user($org);
        $role = $this->role($org, 'ins-fixture');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        RolePermission::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'role_id' => $role->id, 'permission' => Permission::InspectionsExecute->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        DB::table('user_areas')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'user_id' => $user->id, 'area_id' => $area->id]);
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'C', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'checklist_id' => $checklist->id, 'created_by' => $user->id, 'version' => '1', 'status' => 'published', 'published_at' => now()]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'checklist_version_id' => $version->id, 'name' => 'S']);
        Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'checklist_section_id' => $section->id, 'question_text' => 'Q', 'answer_type' => 'boolean', 'status' => true]);

        return [$org, $user, $plant, $area, $version, $role, $checklist];
    }
}
