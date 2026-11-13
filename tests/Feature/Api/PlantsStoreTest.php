<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\OrganizationMembership;
use App\Models\Plant;
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

class PlantsStoreTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_crea_planta_normalizada_y_auditoria_con_dto_exacto(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $response = $this->actingAsApiInOrganization($user, $org)->postJson('/api/plants', [
            'name' => 'Planta Norte', 'code' => ' pl-001 ',
        ])->assertCreated();

        $data = $response->json('data');
        $this->assertEqualsCanonicalizing(['id', 'organization_id', 'name', 'code', 'address', 'description', 'status'], array_keys($data));
        $this->assertSame('PL-001', $data['code']);
        $this->assertSame($org->id, $data['organization_id']);
        $this->assertTrue($data['status']);
        $this->assertNull($data['address']);
        $this->assertNull($data['description']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $data['id']);
        $this->assertDatabaseHas('plants', ['id' => $data['id'], 'organization_id' => $org->id, 'code' => 'PL-001']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'plant.created', 'user_id' => $user->id, 'organization_id' => $org->id, 'auditable_type' => Plant::class, 'auditable_id' => $data['id']]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'plant.created')->count());
        $log = AuditLog::where('action', 'plant.created')->firstOrFail();
        $this->assertSame([], $log->old_values->all());
        $this->assertSame(['name' => 'Planta Norte', 'code' => 'PL-001', 'status' => true], $log->new_values->all());
    }

    public function test_valores_opcionales_se_guardan_en_planta_pero_no_en_auditoria(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $response = $this->actingAsApiInOrganization($user, $org)->postJson('/api/plants', [
            'name' => 'Planta', 'code' => ' p-1 ', 'address' => 'Dirección', 'description' => 'Descripción',
        ])->assertCreated();

        $log = AuditLog::where('action', 'plant.created')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame(Plant::class, $log->auditable_type);
        $this->assertSame($response->json('data.id'), $log->auditable_id);
        $this->assertSame([], $log->old_values->all());
        $this->assertSame(['name' => 'Planta', 'code' => 'P-1', 'status' => true], $log->new_values->all());
        $this->assertArrayNotHasKey('address', $log->new_values->all());
        $this->assertArrayNotHasKey('description', $log->new_values->all());
        $this->assertDatabaseHas('plants', ['id' => $response->json('data.id'), 'address' => 'Dirección', 'description' => 'Descripción']);
        $response->assertJsonPath('data.address', 'Dirección')->assertJsonPath('data.description', 'Descripción');
    }

    public function test_solo_el_json_aporta_atributos_y_se_rechazan_query_y_archivos(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $this->actingAsApiInOrganization($user, $org);

        $this->postJson('/api/plants?name=DesdeQuery', ['code' => 'A'])
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['name', 'query']);
        $this->postJson('/api/plants?code=desde-query', ['name' => 'A'])
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['code', 'query']);

        foreach (['organization_id='.$org->id, 'unknown=valor', 'name=Otro&code=otro'] as $query) {
            $this->postJson('/api/plants?'.$query, ['name' => 'A', 'code' => 'A'])
                ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('query');
        }

        foreach (['organization_id', 'status', 'unknown'] as $field) {
            $this->postJson('/api/plants', ['name' => 'A', 'code' => 'A', $field => null])
                ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors($field);
        }

        $this->call('POST', '/api/plants', [], [], ['attachment' => UploadedFile::fake()->create('extra.txt')],
            $this->transformHeadersToServerVars($this->defaultHeaders + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), json_encode(['name' => 'A', 'code' => 'A']))
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('files');

        $this->assertSame(0, DB::table('plants')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'plant.created')->count());
    }

    public function test_rechaza_entradas_invalidas_y_campos_protegidos(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $this->actingAsApiInOrganization($user, $org);
        $invalid = [
            ['code' => 'A'], ['name' => 'A'], ['name' => str_repeat('a', 151), 'code' => 'A'],
            ['name' => 'A', 'code' => str_repeat('A', 51)], ['name' => [], 'code' => 'A'],
            ['name' => 'A', 'code' => []], ['name' => 'A', 'code' => 'A B'],
            ['name' => 'A', 'code' => 'á'], ['name' => 'A', 'code' => ' '],
            ['name' => 'A', 'code' => 'A', 'address' => []],
            ['name' => 'A', 'code' => 'A', 'description' => 42],
        ];
        foreach (['id', 'organization_id', 'status', 'deleted_at', 'created_at', 'updated_at', 'unknown'] as $key) {
            $invalid[] = ['name' => 'A', 'code' => 'A', $key => null];
        }
        foreach ($invalid as $payload) {
            $this->postJson('/api/plants', $payload)->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        }
        $this->assertSame(0, DB::table('plants')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'plant.created')->count());
    }

    public function test_acepta_limites_y_campos_opcionales(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $this->actingAsApiInOrganization($user, $org)->postJson('/api/plants', [
            'name' => str_repeat('N', 150), 'code' => str_repeat('a', 50),
            'address' => 'Dirección', 'description' => null,
        ])->assertCreated()->assertJsonPath('data.code', str_repeat('A', 50))
            ->assertJsonPath('data.address', 'Dirección')->assertJsonPath('data.description', null);
    }

    public function test_codigo_se_valida_despues_de_normalizar_extremos_y_mayusculas(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $this->actingAsApiInOrganization($user, $org);

        $this->postJson('/api/plants', ['name' => 'A', 'code' => ' '.str_repeat('a', 50).' '])
            ->assertCreated()->assertJsonPath('data.code', str_repeat('A', 50));
        $this->postJson('/api/plants', ['name' => 'B', 'code' => "\u{00A0}\u{200B}b-1\u{00A0}"])
            ->assertCreated()->assertJsonPath('data.code', 'B-1');

        foreach ([str_repeat('a', 51), 'A B', 'á', 'A_B', "A\u{200B}B", '   '] as $code) {
            $this->postJson('/api/plants', ['name' => 'Inválida', 'code' => $code])
                ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('code');
        }

        $this->assertSame(2, DB::table('plants')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'plant.created')->count());
    }

    public function test_organizacion_inactiva_y_operadora_son_rechazadas(): void
    {
        $inactive = $this->clientOrganization('A', ['status' => false]);
        $user = $this->user($inactive);
        $payload = ['name' => 'A', 'code' => 'A'];
        $this->actingAsApiInOrganization($user, $inactive)->postJson('/api/plants', $payload)
            ->assertForbidden()->assertJsonPath('code', 'organization_access_denied');
        $operator = $this->operatorOrganization();
        $this->withHeader('X-Organization-Id', $operator->id)->postJson('/api/plants', $payload)
            ->assertForbidden()->assertJsonPath('code', 'organization_not_tenant');
        $this->assertSame(0, DB::table('plants')->count());
    }

    public function test_errores_de_autenticacion_tenant_y_permiso(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        $payload = ['name' => 'A', 'code' => 'A'];
        $this->postJson('/api/plants', $payload)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer invalid')->postJson('/api/plants', $payload)->assertUnauthorized();
        $this->actingAsApi($user)->postJson('/api/plants', $payload)->assertStatus(400)->assertJsonPath('code', 'organization_required');
        $this->actingAsApiInOrganization($user, $org);
        OrganizationMembership::where('user_id', $user->id)->where('organization_id', $org->id)->update(['status' => OrganizationMembership::STATUS_REVOKED]);
        $this->postJson('/api/plants', $payload)->assertForbidden();
        OrganizationMembership::where('user_id', $user->id)->where('organization_id', $org->id)->update(['status' => OrganizationMembership::STATUS_ACTIVE]);
        $user->status = false;
        $user->save();
        $this->postJson('/api/plants', $payload)->assertUnauthorized();
        $this->assertSame(0, DB::table('plants')->count());
    }

    public function test_rol_nominal_sin_permiso_y_tenant_ajeno_no_autorizan(): void
    {
        $org = $this->clientOrganization('A');
        $other = $this->clientOrganization('B');
        $user = $this->user($org);
        app(TenantContext::class)->set($org->id);
        $user->assignRole($this->role($org, 'Administrador'));
        $this->actingAsApiInOrganization($user, $org)->postJson('/api/plants', ['name' => 'A', 'code' => 'A'])->assertForbidden();
        $this->withHeader('X-Organization-Id', $other->id)->postJson('/api/plants', ['name' => 'A', 'code' => 'A'])->assertForbidden();
        $this->assertSame(0, DB::table('plants')->count());
    }

    public function test_duplicados_normalizados_y_soft_deleted_son_rechazados_y_otro_tenant_es_independiente(): void
    {
        $org = $this->clientOrganization('A');
        $other = $this->clientOrganization('B');
        $user = $this->admin($org);
        $this->actingAsApiInOrganization($user, $org)->postJson('/api/plants', ['name' => 'A', 'code' => 'pl-1'])->assertCreated();
        $this->postJson('/api/plants', ['name' => 'B', 'code' => ' PL-1 '])->assertUnprocessable();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'plant.created')->count());
        app(TenantContext::class)->set($org->id);
        Plant::where('code', 'PL-1')->firstOrFail()->delete();
        $this->postJson('/api/plants', ['name' => 'B', 'code' => 'pl-1'])->assertUnprocessable();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'plant.created')->count());
        $otherUser = $this->admin($other);
        $this->actingAsApiInOrganization($otherUser, $other)->postJson('/api/plants', ['name' => 'C', 'code' => 'pl-1'])->assertCreated();
        $this->assertSame(2, DB::table('plants')->where('code', 'PL-1')->count());
    }

    public function test_fallo_de_auditoria_revierte_planta_y_log(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        app()->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, array $oldValues = [], array $newValues = []): AuditLog
            {
                parent::record($action, $auditable, $oldValues, $newValues);
                throw new \RuntimeException('audit failure');
            }
        });
        $this->actingAsApiInOrganization($user, $org)->postJson('/api/plants', ['name' => 'A', 'code' => 'A'])->assertServerError();
        $this->assertSame(0, DB::table('plants')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'plant.created')->count());
    }

    public function test_colision_real_despues_de_validacion_se_traduce_y_revierte(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        Event::listen('eloquent.creating: '.Plant::class, function (Plant $plant): void {
            if ($plant->name !== 'Carrera') {
                return;
            }
            DB::table('plants')->insert([
                'id' => (string) Str::uuid7(), 'organization_id' => $plant->organization_id,
                'name' => 'Competidora', 'code' => $plant->code, 'status' => true,
            ]);
        });

        $this->actingAsApiInOrganization($user, $org)->postJson('/api/plants', ['name' => 'Carrera', 'code' => ' pl-1 '])
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('code');
        $this->assertSame(0, DB::table('plants')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'plant.created')->count());
    }

    public function test_violacion_de_clave_primaria_no_se_traduce_a_validacion(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        Event::listen('eloquent.creating: '.Plant::class, function (Plant $plant): void {
            if ($plant->name !== 'Colision ID') {
                return;
            }
            DB::table('plants')->insert([
                'id' => $plant->id, 'organization_id' => $plant->organization_id,
                'name' => 'Otra', 'code' => 'OTRO', 'status' => true,
            ]);
        });

        $this->actingAsApiInOrganization($user, $org)->postJson('/api/plants', ['name' => 'Colision ID', 'code' => 'A'])
            ->assertServerError();
        $this->assertSame(0, DB::table('plants')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'plant.created')->count());
    }

    public function test_violacion_de_clave_foranea_no_se_traduce_a_validacion(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->admin($org);
        Event::listen('eloquent.creating: '.Plant::class, function (Plant $plant): void {
            if ($plant->name === 'FK invalida') {
                DB::table('plants')->insert([
                    'id' => (string) Str::uuid7(), 'organization_id' => (string) Str::uuid7(),
                    'name' => 'Invalida', 'code' => 'FK', 'status' => true,
                ]);
            }
        });

        $this->actingAsApiInOrganization($user, $org)->postJson('/api/plants', ['name' => 'FK invalida', 'code' => 'A'])
            ->assertServerError();
        $this->assertSame(0, DB::table('plants')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'plant.created')->count());
    }
}
