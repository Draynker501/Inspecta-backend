<?php

namespace Tests\Feature\Inspections;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Inspection;
use App\Models\Question;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class InspectionReviewHttpTest extends TestCase
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
        $reviewer = $this->user($org);
        $reviewRole = $this->role($org, 'reviewer');
        $reviewer->assignRole($reviewRole);
        RolePermission::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'role_id' => $reviewRole->id, 'permission' => Permission::InspectionsManage->value]);
        DB::table('user_areas')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'user_id' => $reviewer->id, 'area_id' => $area->id]);
        $inspection->update(['status' => 'completed', 'completed_at' => now()]);
        $this->fixture = compact('org', 'owner', 'reviewer', 'reviewRole', 'inspection', 'question');
    }

    private function sendReview(string $body = '{"action":"confirm"}', string $suffix = '', $actor = null, $org = null): TestResponse
    {
        $f = $this->fixture;
        $actor ??= $f['reviewer'];
        $org ??= $f['org'];

        return $this->call('PUT', '/api/inspections/'.$f['inspection']->id.'/status'.$suffix, [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$actor->createToken('test')->plainTextToken,
            'HTTP_X_ORGANIZATION_ID' => $org->id,
        ], $body);
    }

    public function test_rejection_dto_audit_and_repeat(): void
    {
        $f = $this->fixture;
        $answers = DB::table('inspection_answers')->get()->toArray();
        $response = $this->sendReview('{"action":"reject","supervisor_observations":"  motivo privado  "}')->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.supervisor_id', $f['reviewer']->id);
        $this->assertArrayNotHasKey('answers', $response->json('data'));
        $headers = ['Authorization' => 'Bearer '.$f['reviewer']->createToken('read')->plainTextToken, 'X-Organization-Id' => $f['org']->id];
        $this->withHeaders($headers)->getJson('/api/inspections/'.$f['inspection']->id)->assertOk()->assertExactJson($response->json());
        $this->assertEquals($answers, DB::table('inspection_answers')->get()->toArray());
        $audit = DB::table('audit_logs')->where('action', 'inspection.rejected')->sole();
        $this->assertStringNotContainsString('motivo privado', $audit->new_values);
        $this->sendReview()->assertStatus(422)->assertJsonValidationErrors('inspection');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.rejected')->count());
    }

    public function test_invalid_inputs_and_state_precedence(): void
    {
        foreach (['', '[]', 'null', '{', '{}', '{"action":null}', '{"action":"confirm","supervisor_observations":null}',
            '{"action":"reject"}', '{"action":"reject","supervisor_observations":null}',
            '{"action":"reject","supervisor_observations":42}', '{"action":"reject","supervisor_observations":"  "}',
            json_encode(['action' => 'reject', 'supervisor_observations' => "\u{0085}"]),
            json_encode(['action' => 'reject', 'supervisor_observations' => "\u{2028}"]),
            '{"action":"reject","supervisor_observations":"motivo","extra":1}',
            '{"action":"confirm","supervisor_observations":"motivo"}',
            '{"action":"confirm","extra":1}', json_encode(['action' => 'reject', 'supervisor_observations' => str_repeat('ñ', 32768)])] as $body) {
            $this->sendReview($body)->assertStatus(422)->assertJsonValidationErrors('fields');
        }
        $this->sendReview('{"action":"confirm"}', '?x=1')->assertStatus(422)->assertJsonValidationErrors('fields');
        DB::table('inspections')->where('id', $this->fixture['inspection']->id)->update(['status' => 'draft']);
        $this->sendReview('{}')->assertStatus(422)->assertJsonValidationErrors('fields');
        $this->sendReview()->assertStatus(422)->assertJsonValidationErrors('inspection');
        DB::table('inspections')->where('id', $this->fixture['inspection']->id)->update(['status' => 'completed', 'supervisor_observations' => 'previous']);
        $this->sendReview()->assertStatus(422)->assertJsonValidationErrors('inspection');
        $this->assertSame(0, DB::table('audit_logs')->whereIn('action', ['inspection.confirmed', 'inspection.rejected'])->count());
    }

    public function test_visibility_authorization_and_context_precede_input(): void
    {
        $f = $this->fixture;
        $url = '/api/inspections/'.$f['inspection']->id.'/status';
        $this->call('PUT', $url, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{}')->assertUnauthorized();
        $this->call('PUT', $url, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$f['reviewer']->createToken('test')->plainTextToken], '{}')->assertStatus(400);
        $other = $this->clientOrganization('OTHER04');
        $actor = $this->user($other);
        $this->sendReview('{}', '?x=1', $actor, $other)->assertNotFound();
        $this->sendReview('{}', '?x=1', $f['owner'])->assertForbidden();
        DB::table('inspections')->where('id', $f['inspection']->id)->update(['user_id' => $f['reviewer']->id]);
        $this->sendReview('{}', '?x=1')->assertForbidden();
    }

    public function test_confirm_and_audit_failure_rollback(): void
    {
        $f = $this->fixture;
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit failure'));
        $this->sendReview()->assertStatus(500);
        app(TenantContext::class)->set($f['org']->id);
        $this->assertSame('completed', $f['inspection']->fresh()->status);
        $this->assertNull($f['inspection']->fresh()->supervisor_id);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.confirmed')->count());
        app()->forgetInstance(AuditLogger::class);
        $this->sendReview()->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->sendReview()->assertStatus(422)->assertJsonValidationErrors('inspection');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.confirmed')->count());
    }
}
