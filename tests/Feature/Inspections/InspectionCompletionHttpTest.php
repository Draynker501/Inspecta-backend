<?php

namespace Tests\Feature\Inspections;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Inspection;
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
}
