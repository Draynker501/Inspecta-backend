<?php

namespace Tests\Feature\Inspections;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Inspection;
use App\Models\Plant;
use App\Models\Question;
use App\Models\RolePermission;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class InspectionCompletionHttpTest extends TestCase
{
    use TenantFixtures;

    private array $fixture;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSame(':memory:', DB::connection()->getDatabaseName());
            Artisan::call('migrate', ['--force' => true]);
        } else {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
        $org = $this->clientOrganization('INS03');
        app(TenantContext::class)->set($org->id);
        $plant = $this->plant($org, 'P1');
        $area = $this->area($plant, 'A1');
        $owner = $this->user($org);
        $role = $this->role($org, 'executor');
        app(TenantContext::class)->runAs($org->id, fn () => $owner->assignRole($role));
        RolePermission::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'role_id' => $role->id, 'permission' => Permission::InspectionsExecute->value]);
        DB::table('user_areas')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'user_id' => $owner->id, 'area_id' => $area->id]);
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'Checklist', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'created_by' => $owner->id, 'version' => '1', 'status' => 'published', 'published_at' => now()]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'Sección', 'display_order' => 1]);
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Pregunta', 'answer_type' => 'boolean', 'display_order' => 1, 'required' => true, 'status' => true]);
        $inspection = Inspection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'user_id' => $owner->id, 'checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id, 'status' => 'draft', 'started_at' => now()->subDay()]);
        DB::table('inspection_answers')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'inspection_id' => $inspection->id, 'question_id' => $question->id, 'checklist_version_id' => $version->id, 'checklist_section_id' => $section->id, 'answer' => 'false', 'created_at' => now(), 'updated_at' => now()]);
        $this->fixture = compact('org', 'owner', 'role', 'inspection', 'question');
    }

    private function newInspectionPayload(): array
    {
        $inspection = $this->fixture['inspection'];

        return [
            'checklist_version_id' => $inspection->checklist_version_id,
            'plant_id' => $inspection->plant_id,
            'area_id' => $inspection->area_id,
        ];
    }

    private function sendComplete(string $body = '{}', string $suffix = ''): TestResponse
    {
        $f = $this->fixture;

        return $this->call('POST', '/api/inspections/'.$f['inspection']->id.'/complete'.$suffix, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$f['owner']->createToken('test')->plainTextToken,
            'HTTP_X_ORGANIZATION_ID' => $f['org']->id,
        ], $body);
    }

    public function test_completion_and_read_only_afterwards(): void
    {
        $f = $this->fixture;
        $response = $this->sendComplete()->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertNotNull($response->json('data.completed_at'));
        $headers = ['Authorization' => 'Bearer '.$f['owner']->createToken('read')->plainTextToken, 'X-Organization-Id' => $f['org']->id];
        $this->withHeaders($headers)->getJson('/api/inspections/'.$f['inspection']->id)->assertOk()->assertExactJson($response->json());
        $this->withHeaders($headers)->getJson('/api/inspections/'.$f['inspection']->id.'/answers')->assertOk();
        $path = '/api/inspections/'.$f['inspection']->id.'/answers/'.$f['question']->id;
        $this->withHeaders($headers)->putJson($path, ['answer' => 'true'])->assertStatus(422)->assertJsonValidationErrors('inspection');
        $this->withHeaders($headers)->deleteJson($path)->assertStatus(422)->assertJsonValidationErrors('inspection');
        $this->sendComplete()->assertStatus(422)->assertJsonValidationErrors('inspection');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.completed')->count());
    }

    public function test_invalid_body_query_and_incomplete_inspection(): void
    {
        foreach (['', '[]', 'null', '{"status":"completed"}', '{'] as $body) {
            $this->sendComplete($body)->assertStatus(422)->assertJsonValidationErrors('fields');
        }
        $this->sendComplete('{}', '?foo=bar')->assertStatus(422)->assertJsonValidationErrors('fields');
        DB::table('inspection_answers')->delete();
        $this->sendComplete()->assertStatus(422)->assertJsonValidationErrors('answers');
        $this->assertSame('draft', $this->fixture['inspection']->fresh()->status);
    }

    public function test_visibility_and_permission_precede_input_validation(): void
    {
        $f = $this->fixture;
        $other = $this->clientOrganization('OTHER');
        $actor = $this->user($other);
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('test')->plainTextToken, 'X-Organization-Id' => $other->id];
        $this->withHeaders($headers)->postJson('/api/inspections/'.$f['inspection']->id.'/complete?x=1', ['x' => 1])->assertNotFound();
        $manager = $this->user($f['org']);
        $role = $this->role($f['org'], 'manager');
        app(TenantContext::class)->runAs($f['org']->id, fn () => $manager->assignRole($role));
        RolePermission::create(['id' => (string) Str::uuid7(), 'organization_id' => $f['org']->id, 'role_id' => $role->id, 'permission' => Permission::InspectionsManage->value]);
        DB::table('user_areas')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $f['org']->id, 'user_id' => $manager->id, 'area_id' => $f['inspection']->area_id]);
        $headers = ['Authorization' => 'Bearer '.$manager->createToken('test')->plainTextToken, 'X-Organization-Id' => $f['org']->id];
        $this->withHeaders($headers)->postJson('/api/inspections/'.$f['inspection']->id.'/complete?x=1', ['x' => 1])->assertForbidden();
    }

    public function test_authentication_and_tenant_are_required(): void
    {
        $f = $this->fixture;
        $url = '/api/inspections/'.$f['inspection']->id.'/complete';
        $this->call('POST', $url, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], '{}')->assertUnauthorized();
        $this->call('POST', $url, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$f['owner']->createToken('test')->plainTextToken,
        ], '{}')->assertStatus(400);
    }

    public function test_inspeccion_existente_permanece_accesible_con_ubicacion_inactiva(): void
    {
        $f = $this->fixture;

        DB::table('plants')
            ->where('id', $f['inspection']->plant_id)
            ->update(['status' => false]);

        DB::table('areas')
            ->where('id', $f['inspection']->area_id)
            ->update(['status' => false]);

        $this->actingAsApiInOrganization(
            $f['owner'],
            $f['org'],
        );

        $url = '/api/inspections/'.$f['inspection']->id;

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.id', $f['inspection']->id)
            ->assertJsonPath('data.status', 'draft');

        $this->getJson($url.'/answers')
            ->assertOk();

        $this->assertDatabaseHas('inspections', [
            'id' => $f['inspection']->id,
            'plant_id' => $f['inspection']->plant_id,
            'area_id' => $f['inspection']->area_id,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('inspection_answers', [
            'inspection_id' => $f['inspection']->id,
            'question_id' => $f['question']->id,
        ]);
    }

    public function test_inspeccion_iniciada_puede_completarse_con_ubicacion_inactiva(): void
    {
        $f = $this->fixture;

        DB::table('plants')
            ->where('id', $f['inspection']->plant_id)
            ->update(['status' => false]);

        DB::table('areas')
            ->where('id', $f['inspection']->area_id)
            ->update(['status' => false]);

        $this->sendComplete()
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('inspections', [
            'id' => $f['inspection']->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $f['inspection']->id,
            'action' => 'inspection.completed',
        ]);
    }

    public function test_no_permite_crear_inspeccion_en_planta_inactiva(): void
    {
        $f = $this->fixture;

        $this->actingAsApiInOrganization(
            $f['owner'],
            $f['org'],
        );

        DB::table('plants')
            ->where('id', $f['inspection']->plant_id)
            ->update(['status' => false]);

        $this->postJson(
            '/api/inspections',
            $this->newInspectionPayload(),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resources']);

        $this->assertDatabaseCount('inspections', 1);
    }

    public function test_no_permite_crear_inspeccion_en_area_inactiva(): void
    {
        $f = $this->fixture;

        $this->actingAsApiInOrganization(
            $f['owner'],
            $f['org'],
        );

        DB::table('areas')
            ->where('id', $f['inspection']->area_id)
            ->update(['status' => false]);

        $this->postJson(
            '/api/inspections',
            $this->newInspectionPayload(),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resources']);

        $this->assertDatabaseCount('inspections', 1);
    }

    public function test_no_permite_crear_inspeccion_en_linea_inactiva(): void
    {
        $f = $this->fixture;

        $area = app(TenantContext::class)->runAs(
            $f['org']->id,
            fn () => Area::query()->findOrFail(
                $f['inspection']->area_id,
            ),
        );

        $line = $this->productionLine($area, 'INS-L01');

        DB::table('production_lines')
            ->where('id', $line->id)
            ->update(['status' => false]);

        $this->actingAsApiInOrganization(
            $f['owner'],
            $f['org'],
        );

        $this->postJson('/api/inspections', array_merge(
            $this->newInspectionPayload(),
            ['production_line_id' => $line->id],
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resources']);

        $this->assertDatabaseCount('inspections', 1);
    }

    public function test_reactivacion_permite_crear_nuevas_inspecciones(): void
    {
        $f = $this->fixture;

        $this->actingAsApiInOrganization(
            $f['owner'],
            $f['org'],
        );

        $payload = $this->newInspectionPayload();

        DB::table('plants')
            ->where('id', $f['inspection']->plant_id)
            ->update(['status' => false]);

        DB::table('areas')
            ->where('id', $f['inspection']->area_id)
            ->update(['status' => false]);

        $this->postJson('/api/inspections', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resources']);

        DB::table('plants')
            ->where('id', $f['inspection']->plant_id)
            ->update(['status' => true]);

        $this->postJson('/api/inspections', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resources']);

        DB::table('areas')
            ->where('id', $f['inspection']->area_id)
            ->update(['status' => true]);

        $id = $this->postJson('/api/inspections', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->assertDatabaseCount('inspections', 2);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $id,
            'action' => 'inspection.created',
        ]);
    }

    private function grantFindingsPermission(): void
    {
        $f = $this->fixture;

        app(TenantContext::class)->runAs(
            $f['org']->id,
            function () use ($f): void {
                RolePermission::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $f['org']->id,
                    'role_id' => $f['role']->id,
                    'permission' => Permission::FindingsRegister->value,
                ]);
            },
        );

        app(TenantContext::class)->flushPermissionCache(
            $f['owner']->id,
            $f['org']->id,
        );
    }

    public function test_permite_incidente_vinculado_a_inspeccion_con_ubicacion_inactiva(): void
    {
        $f = $this->fixture;

        $this->grantFindingsPermission();

        $this->actingAsApiInOrganization(
            $f['owner'],
            $f['org'],
        );

        DB::table('plants')
            ->where('id', $f['inspection']->plant_id)
            ->update(['status' => false]);

        DB::table('areas')
            ->where('id', $f['inspection']->area_id)
            ->update(['status' => false]);

        $payload = [
            'inspection_id' => $f['inspection']->id,
            'question_id' => $f['question']->id,
            'plant_id' => $f['inspection']->plant_id,
            'area_id' => $f['inspection']->area_id,
            'type' => 'unsafe_condition',
            'title' => 'Hallazgo durante inspección',
            'description' => 'Condición insegura detectada durante la inspección.',
            'risk_level' => 'high',
        ];

        $id = $this->postJson('/api/incidents', $payload)
            ->assertCreated()
            ->assertJsonPath(
                'data.inspection_id',
                $f['inspection']->id,
            )
            ->assertJsonPath(
                'data.question_id',
                $f['question']->id,
            )
            ->assertJsonPath('data.status', 'open')
            ->json('data.id');

        $this->assertDatabaseHas('incidents', [
            'id' => $id,
            'organization_id' => $f['org']->id,
            'inspection_id' => $f['inspection']->id,
            'question_id' => $f['question']->id,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $id,
            'action' => 'incident.created',
        ]);
    }

    public function test_sin_inspeccion_vinculada_no_permite_incidente_en_ubicacion_inactiva(): void
    {
        $f = $this->fixture;

        $this->grantFindingsPermission();

        $this->actingAsApiInOrganization(
            $f['owner'],
            $f['org'],
        );

        DB::table('plants')
            ->where('id', $f['inspection']->plant_id)
            ->update(['status' => false]);

        DB::table('areas')
            ->where('id', $f['inspection']->area_id)
            ->update(['status' => false]);

        $this->postJson('/api/incidents', [
            'plant_id' => $f['inspection']->plant_id,
            'area_id' => $f['inspection']->area_id,
            'type' => 'unsafe_condition',
            'title' => 'Incidente independiente',
            'description' => 'Intento de registrar una nueva incidencia.',
            'risk_level' => 'high',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'plant_id',
                'area_id',
            ]);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_incidente_vinculado_rechaza_ubicacion_incoherente(): void
    {
        $f = $this->fixture;

        $this->grantFindingsPermission();

        $plant = app(TenantContext::class)->runAs(
            $f['org']->id,
            fn () => Plant::query()->findOrFail(
                $f['inspection']->plant_id,
            ),
        );

        $otherArea = $this->area($plant, 'OTHER-AREA');

        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'user_id' => $f['owner']->id,
            'area_id' => $otherArea->id,
        ]);

        $this->actingAsApiInOrganization(
            $f['owner'],
            $f['org'],
        );

        DB::table('areas')
            ->where('id', $otherArea->id)
            ->update(['status' => false]);

        $response = $this->postJson('/api/incidents', [
            'inspection_id' => $f['inspection']->id,
            'plant_id' => $plant->id,
            'area_id' => $otherArea->id,
            'type' => 'unsafe_condition',
            'title' => 'Incidente con ubicación incoherente',
            'description' => 'La ubicación no corresponde a la inspección.',
            'risk_level' => 'high',
        ]);

        $this->assertContains(
            $response->status(),
            [404, 422],
        );

        $this->assertDatabaseCount('incidents', 0);
    }
}
