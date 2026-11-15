<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\AuditLog;
use App\Models\OrganizationMembership;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class AreasStoreTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_crea_area_con_dto_uuid_y_auditoria_minimizada(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $response = $this->actingAsApiInOrganization($user, $org)
            ->postJson("/api/plants/{$plant->id}/areas", [
                'name' => 'Área Norte', 'code' => ' aa-01 ', 'type' => 'libre', 'description' => 'privada',
            ])->assertCreated();
        $data = $response->json('data');
        $this->assertEqualsCanonicalizing(['id', 'organization_id', 'plant_id', 'name', 'code', 'type', 'description', 'status'], array_keys($data));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $data['id']);
        $this->assertSame('AA-01', $data['code']);
        $this->assertSame($org->id, $data['organization_id']);
        $this->assertSame($plant->id, $data['plant_id']);
        $this->assertTrue($data['status']);
        $this->assertSame('libre', $data['type']);
        $this->assertSame('privada', $data['description']);
        $log = AuditLog::where('action', 'area.created')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame(Area::class, $log->auditable_type);
        $this->assertSame($data['id'], $log->auditable_id);
        $this->assertSame([], $log->old_values->all());
        $this->assertSame(['plant_id' => $plant->id, 'name' => 'Área Norte', 'code' => 'AA-01', 'status' => true], $log->new_values->all());
    }

    public function test_opcionales_nullable_y_duplicidad_por_planta_incluye_soft_deleted(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $p1 = $this->plant($org, 'P1');
        $p2 = $this->plant($org, 'P2');
        $this->actingAsApiInOrganization($user, $org);
        $a = $this->postJson("/api/plants/{$p1->id}/areas", ['name' => 'A', 'code' => 'a-1'])->assertCreated();
        $a->assertJsonPath('data.type', null)->assertJsonPath('data.description', null);
        $this->postJson("/api/plants/{$p2->id}/areas", ['name' => 'B', 'code' => 'a-1', 'type' => null])->assertCreated();
        $this->postJson("/api/plants/{$p1->id}/areas", ['name' => 'C', 'code' => ' A-1 '])->assertUnprocessable()->assertJsonValidationErrors('code');
        DB::table('areas')->where('id', $a->json('data.id'))->update(['deleted_at' => now()]);
        $this->postJson("/api/plants/{$p1->id}/areas", ['name' => 'C', 'code' => 'a-1'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'area.created')->count());
    }

    public function test_permiso_sin_view_ni_asignacion_es_suficiente(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->user($org);
        app(TenantContext::class)->set($org->id);
        $role = $this->role($org, 'Gestor');
        $user->assignRole($role);
        RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $org->id, 'permission' => Permission::PlantsManage->value]);
        $plant = $this->plant($org, 'P');
        $this->actingAsApiInOrganization($user, $org)->postJson("/api/plants/{$plant->id}/areas", ['name' => 'A', 'code' => 'A'])->assertCreated();
    }

    public function test_auth_permiso_membresia_y_ocultacion_del_padre(): void
    {
        $org = $this->clientOrganization('A');
        $other = $this->clientOrganization('B');
        $admin = $this->admin($org);
        $plant = $this->plant($org, 'P');
        app(TenantContext::class)->set($other->id);
        $foreign = $this->plant($other, 'Q');
        $path = "/api/plants/{$plant->id}/areas";
        $payload = ['name' => 'A', 'code' => 'A'];
        $this->postJson($path, $payload)->assertUnauthorized();
        $unprivileged = $this->user($org);
        $this->actingAsApiInOrganization($unprivileged, $org)->postJson("/api/plants/{$foreign->id}/areas", $payload)->assertForbidden();
        $this->actingAsApiInOrganization($admin, $org);
        $foreignResponse = $this->postJson("/api/plants/{$foreign->id}/areas", $payload)->assertNotFound();
        $missingResponse = $this->postJson('/api/plants/inexistente/areas', $payload)->assertNotFound();
        $this->assertSame($missingResponse->json(), $foreignResponse->json());
        DB::table('plants')->where('id', $plant->id)->update(['status' => false]);
        $this->postJson($path, $payload)->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('plantId');
        DB::table('plants')->where('id', $plant->id)->update(['deleted_at' => now()]);
        $deletedResponse = $this->postJson($path, $payload)->assertNotFound();
        $this->assertSame($missingResponse->json(), $deletedResponse->json());
        OrganizationMembership::where('user_id', $admin->id)->where('organization_id', $org->id)->update(['status' => OrganizationMembership::STATUS_REVOKED]);
        $this->postJson($path, $payload)->assertForbidden();
        $this->assertSame(0, DB::table('areas')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'area.created')->count());
    }

    public function test_entradas_invalidas_query_y_contenido(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $path = "/api/plants/{$plant->id}/areas";
        $this->actingAsApiInOrganization($user, $org);
        foreach (['id', 'plant_id', 'organization_id', 'status', 'created_at', 'updated_at', 'deleted_at', 'unknown'] as $field) {
            $this->postJson($path, ['name' => 'A', 'code' => 'A', $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([['code' => 'A'], ['name' => 'A'], ['name' => ' ', 'code' => 'A'], ['name' => str_repeat('x', 151), 'code' => 'A'], ['name' => 'A', 'code' => 'á'], ['name' => 'A', 'code' => 'A_B'], ['name' => 'A', 'code' => str_repeat('a', 51)], ['name' => 'A', 'code' => 'A', 'type' => str_repeat('a', 51)], ['name' => 'A', 'code' => 'A', 'description' => []]] as $payload) {
            $this->postJson($path, $payload)->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        }
        $this->postJson($path.'?x=1', ['name' => 'A', 'code' => 'A'])->assertUnprocessable()->assertJsonValidationErrors('query');
        $this->post($path, ['name' => 'A', 'code' => 'A', 'file' => UploadedFile::fake()->create('x.txt')], ['Accept' => 'application/json'])->assertStatus(415);
        $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars($this->defaultHeaders + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), '{broken')
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        $this->assertSame(0, DB::table('areas')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'area.created')->count());
    }

    public function test_fallo_de_auditoria_revierte_area_y_log(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        app()->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, array $oldValues = [], array $newValues = []): AuditLog
            {
                parent::record($action, $auditable, $oldValues, $newValues);
                throw new \RuntimeException('audit failure');
            }
        });
        $this->actingAsApiInOrganization($user, $org)->postJson("/api/plants/{$plant->id}/areas", ['name' => 'A', 'code' => 'A'])->assertServerError();
        $this->assertSame(0, DB::table('areas')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'area.created')->count());
    }

    public function test_cambio_de_tenant_resuelve_padre_y_permisos_del_contexto_actual(): void
    {
        $one = $this->clientOrganization('A');
        $two = $this->clientOrganization('B');
        $user = $this->admin($one);
        $p1 = $this->plant($one, 'P1');
        OrganizationMembership::create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'organization_id' => $two->id, 'status' => 'active']);
        app(TenantContext::class)->set($two->id);
        $role = $this->role($two, 'Gestor B');
        $user->assignRole($role);
        RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $two->id, 'permission' => Permission::PlantsManage->value]);
        $p2 = $this->plant($two, 'P2');
        $this->actingAsApiInOrganization($user, $one);
        $this->postJson("/api/plants/{$p1->id}/areas", ['name' => 'A', 'code' => 'A'])->assertCreated()->assertJsonPath('data.organization_id', $one->id);
        $this->withHeader('X-Organization-Id', $two->id);
        $this->postJson("/api/plants/{$p1->id}/areas", ['name' => 'B', 'code' => 'B'])->assertNotFound();
        $this->postJson("/api/plants/{$p2->id}/areas", ['name' => 'B', 'code' => 'B'])->assertCreated()->assertJsonPath('data.organization_id', $two->id);
    }

    public function test_colision_de_codigo_se_traduce_pero_clave_primaria_no(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        Event::listen('eloquent.creating: '.Area::class, function (Area $area): void {
            if (! in_array($area->name, ['Carrera', 'Colision ID'], true)) {
                return;
            }
            DB::table('areas')->insert([
                'id' => $area->name === 'Carrera' ? (string) Str::uuid7() : $area->id,
                'plant_id' => $area->plant_id, 'organization_id' => $area->organization_id,
                'name' => 'Otra', 'code' => $area->name === 'Carrera' ? $area->code : 'OTRO', 'status' => true,
            ]);
        });
        $this->actingAsApiInOrganization($user, $org);
        $this->postJson("/api/plants/{$plant->id}/areas", ['name' => 'Carrera', 'code' => 'A'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson("/api/plants/{$plant->id}/areas", ['name' => 'Colision ID', 'code' => 'A'])->assertServerError();
        $this->assertSame(0, DB::table('areas')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'area.created')->count());
    }

    public function test_fallo_fk_no_se_traduce_a_duplicado(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        Event::listen('eloquent.creating: '.Area::class, function (Area $area): void {
            if ($area->name === 'FK invalida') {
                DB::table('areas')->insert([
                    'id' => (string) Str::uuid7(), 'plant_id' => (string) Str::uuid7(),
                    'organization_id' => $area->organization_id, 'name' => 'Otra', 'code' => 'OTRO', 'status' => true,
                ]);
            }
        });
        $this->actingAsApiInOrganization($user, $org)->postJson("/api/plants/{$plant->id}/areas", ['name' => 'FK invalida', 'code' => 'A'])->assertServerError();
        $this->assertSame(0, DB::table('areas')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'area.created')->count());
    }
}
