<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\AuditLog;
use App\Models\OrganizationMembership;
use App\Models\ProductionLine;
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

class ProductionLinesStoreTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_crea_linea_con_dto_uuid_y_auditoria_minimizada(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'AR');
        $response = $this->actingAsApiInOrganization($user, $org)
            ->postJson("/api/areas/{$area->id}/lines", [
                'name' => 'Línea Norte', 'code' => ' ln-01 ', 'description' => 'principal',
            ])->assertCreated();
        $data = $response->json('data');
        $this->assertEqualsCanonicalizing(['id', 'organization_id', 'area_id', 'name', 'code', 'description', 'status'], array_keys($data));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $data['id']);
        $this->assertSame('LN-01', $data['code']);
        $this->assertSame($org->id, $data['organization_id']);
        $this->assertSame($area->id, $data['area_id']);
        $this->assertTrue($data['status']);
        $this->assertSame('principal', $data['description']);
        $log = AuditLog::where('action', 'production_line.created')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame(ProductionLine::class, $log->auditable_type);
        $this->assertSame($data['id'], $log->auditable_id);
        $this->assertSame([], $log->old_values->all());
        $this->assertSame(['area_id' => $area->id, 'name' => 'Línea Norte', 'code' => 'LN-01', 'status' => true], $log->new_values->all());
    }

    public function test_opcionales_nullable_y_duplicidad_por_area_incluye_soft_deleted(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $p1 = $this->plant($org, 'P1');
        $p2 = $this->plant($org, 'P2');
        $a1 = $this->area($p1, 'A1');
        $a2 = $this->area($p2, 'A2');
        $this->actingAsApiInOrganization($user, $org);
        $r = $this->postJson("/api/areas/{$a1->id}/lines", ['name' => 'L', 'code' => 'l-1'])->assertCreated();
        $r->assertJsonPath('data.description', null);
        $this->postJson("/api/areas/{$a2->id}/lines", ['name' => 'L2', 'code' => 'l-1'])->assertCreated();
        $this->postJson("/api/areas/{$a1->id}/lines", ['name' => 'L3', 'code' => ' L-1 '])->assertUnprocessable()->assertJsonValidationErrors('code');
        DB::table('production_lines')->where('id', $r->json('data.id'))->update(['deleted_at' => now()]);
        $this->postJson("/api/areas/{$a1->id}/lines", ['name' => 'L3', 'code' => 'l-1'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'production_line.created')->count());
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
        $area = $this->area($plant, 'AR');
        $this->actingAsApiInOrganization($user, $org)->postJson("/api/areas/{$area->id}/lines", ['name' => 'L', 'code' => 'L'])->assertCreated();
    }

    public function test_auth_permiso_membresia_y_ocultacion_del_padre(): void
    {
        $org = $this->clientOrganization('A');
        $other = $this->clientOrganization('B');
        $admin = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'AR');
        app(TenantContext::class)->set($other->id);
        $foreignPlant = $this->plant($other, 'Q');
        $foreignArea = $this->area($foreignPlant, 'FAR');
        $path = "/api/areas/{$area->id}/lines";
        $payload = ['name' => 'L', 'code' => 'L'];
        $this->postJson($path, $payload)->assertUnauthorized();
        $unprivileged = $this->user($org);
        $this->actingAsApiInOrganization($unprivileged, $org)->postJson("/api/areas/{$foreignArea->id}/lines", $payload)->assertForbidden();
        $this->actingAsApiInOrganization($admin, $org);
        $foreignResponse = $this->postJson("/api/areas/{$foreignArea->id}/lines", $payload)->assertNotFound();
        $missingResponse = $this->postJson('/api/areas/inexistente/lines', $payload)->assertNotFound();
        $this->assertSame($missingResponse->json(), $foreignResponse->json());
        DB::table('areas')->where('id', $area->id)->update(['status' => false]);
        $this->postJson($path, $payload)->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('areaId');
        DB::table('areas')->where('id', $area->id)->update(['deleted_at' => now()]);
        $deletedResponse = $this->postJson($path, $payload)->assertNotFound();
        $this->assertSame($missingResponse->json(), $deletedResponse->json());
        OrganizationMembership::where('user_id', $admin->id)->where('organization_id', $org->id)->update(['status' => OrganizationMembership::STATUS_REVOKED]);
        $this->postJson($path, $payload)->assertForbidden();
        $this->assertSame(0, DB::table('production_lines')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'production_line.created')->count());
    }

    public function test_planta_inactiva_devuelve_422_plant_id(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'AR');
        DB::table('plants')->where('id', $plant->id)->update(['status' => false]);
        $this->actingAsApiInOrganization($user, $org)
            ->postJson("/api/areas/{$area->id}/lines", ['name' => 'L', 'code' => 'L'])
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('plantId');
        $this->assertSame(0, DB::table('production_lines')->count());
    }

    public function test_entradas_invalidas_query_y_contenido(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'P');
        $path = "/api/areas/{$area->id}/lines";
        $this->actingAsApiInOrganization($user, $org);
        foreach (['id', 'area_id', 'organization_id', 'status', 'created_at', 'updated_at', 'deleted_at', 'unknown', 'plant_id', 'type'] as $field) {
            $this->postJson($path, ['name' => 'L', 'code' => 'L', $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([['code' => 'L'], ['name' => 'L'], ['name' => ' ', 'code' => 'L'], ['name' => str_repeat('x', 151), 'code' => 'L'], ['name' => 'L', 'code' => 'á'], ['name' => 'L', 'code' => 'L_B'], ['name' => 'L', 'code' => str_repeat('a', 51)], ['name' => 'L', 'code' => 'L', 'description' => []]] as $payload) {
            $this->postJson($path, $payload)->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        }
        $this->postJson($path.'?x=1', ['name' => 'L', 'code' => 'L'])->assertUnprocessable()->assertJsonValidationErrors('query');
        $this->post($path, ['name' => 'L', 'code' => 'L', 'file' => UploadedFile::fake()->create('x.txt')], ['Accept' => 'application/json'])->assertStatus(415);
        $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars($this->defaultHeaders + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), '{broken')
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        $this->assertSame(0, DB::table('production_lines')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'production_line.created')->count());
    }

    public function test_fallo_de_auditoria_revierte_linea_y_log(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'AR');
        app()->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, array $oldValues = [], array $newValues = []): AuditLog
            {
                parent::record($action, $auditable, $oldValues, $newValues);
                throw new \RuntimeException('audit failure');
            }
        });
        $this->actingAsApiInOrganization($user, $org)->postJson("/api/areas/{$area->id}/lines", ['name' => 'L', 'code' => 'L'])->assertServerError();
        $this->assertSame(0, DB::table('production_lines')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'production_line.created')->count());
    }

    public function test_colision_de_codigo_se_traduce_pero_clave_primaria_no(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'AR');
        Event::listen('eloquent.creating: '.ProductionLine::class, function (ProductionLine $line): void {
            if (! in_array($line->name, ['Carrera', 'Colision ID'], true)) {
                return;
            }
            DB::table('production_lines')->insert([
                'id' => $line->name === 'Carrera' ? (string) Str::uuid7() : $line->id,
                'area_id' => $line->area_id, 'organization_id' => $line->organization_id,
                'name' => 'Otra', 'code' => $line->name === 'Carrera' ? $line->code : 'OTRO', 'status' => true,
            ]);
        });
        $this->actingAsApiInOrganization($user, $org);
        $this->postJson("/api/areas/{$area->id}/lines", ['name' => 'Carrera', 'code' => 'L'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson("/api/areas/{$area->id}/lines", ['name' => 'Colision ID', 'code' => 'L'])->assertServerError();
        $this->assertSame(0, DB::table('production_lines')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'production_line.created')->count());
    }

    public function test_fallo_fk_no_se_traduce_a_duplicado(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'AR');
        Event::listen('eloquent.creating: '.ProductionLine::class, function (ProductionLine $line): void {
            if ($line->name === 'FK invalida') {
                DB::table('production_lines')->insert([
                    'id' => (string) Str::uuid7(), 'area_id' => (string) Str::uuid7(),
                    'organization_id' => $line->organization_id, 'name' => 'Otra', 'code' => 'OTRO', 'status' => true,
                ]);
            }
        });
        $this->actingAsApiInOrganization($user, $org)->postJson("/api/areas/{$area->id}/lines", ['name' => 'FK invalida', 'code' => 'L'])->assertServerError();
        $this->assertSame(0, DB::table('production_lines')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'production_line.created')->count());
    }

    public function test_cambio_de_tenant_resuelve_padre_y_permisos_del_contexto_actual(): void
    {
        $one = $this->clientOrganization('A');
        $two = $this->clientOrganization('B');
        $user = $this->admin($one);
        $p1 = $this->plant($one, 'P1');
        $a1 = $this->area($p1, 'A1');
        OrganizationMembership::create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'organization_id' => $two->id, 'status' => 'active']);
        app(TenantContext::class)->set($two->id);
        $role = $this->role($two, 'Gestor B');
        $user->assignRole($role);
        RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $two->id, 'permission' => Permission::PlantsManage->value]);
        $p2 = $this->plant($two, 'P2');
        $a2 = $this->area($p2, 'A2');
        $this->actingAsApiInOrganization($user, $one);
        $this->postJson("/api/areas/{$a1->id}/lines", ['name' => 'L', 'code' => 'L'])->assertCreated()->assertJsonPath('data.organization_id', $one->id);
        $this->withHeader('X-Organization-Id', $two->id);
        $this->postJson("/api/areas/{$a1->id}/lines", ['name' => 'L2', 'code' => 'L2'])->assertNotFound();
        $this->postJson("/api/areas/{$a2->id}/lines", ['name' => 'L2', 'code' => 'L2'])->assertCreated()->assertJsonPath('data.organization_id', $two->id);
    }

    public function test_referencia_obsoleta_entre_area_y_planta_se_rechaza(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $secondPlant = $this->plant($org, 'P2');
        $area = $this->area($plant, 'AR');
        $updated = false;
        Event::listen('eloquent.retrieved: '.Area::class, function (Area $retrieved) use ($area, $secondPlant, &$updated): void {
            if (! $updated && $retrieved->id === $area->id && $retrieved->plant_id !== $secondPlant->id) {
                $updated = true;
                DB::table('areas')->where('id', $area->id)->update(['plant_id' => $secondPlant->id]);
            }
        });
        $this->actingAsApiInOrganization($user, $org)
            ->postJson("/api/areas/{$area->id}/lines", ['name' => 'L', 'code' => 'L'])
            ->assertNotFound();
        $this->assertSame(0, DB::table('production_lines')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'production_line.created')->count());
    }
}
