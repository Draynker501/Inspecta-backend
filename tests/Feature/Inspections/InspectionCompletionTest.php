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
use App\Support\Inspections\InspectionCompletion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class InspectionCompletionTest extends TestCase
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

    public function test_completion_is_atomic_audited_and_not_repeatable(): void
    {
        $f = $this->fixture;
        $this->travelTo(now()->startOfSecond());
        $result = app(InspectionCompletion::class)->complete($f['owner'], $f['inspection']->id);
        $this->assertSame('completed', $result->status);
        $this->assertTrue($result->completed_at->equalTo(now()));
        $this->assertEquals($f['inspection']->started_at, $result->started_at);
        $audit = DB::table('audit_logs')->where('action', 'inspection.completed')->sole();
        $this->assertSame($f['owner']->id, $audit->user_id);
        $this->assertSame($f['org']->id, $audit->organization_id);
        $this->assertSame($result->id, $audit->auditable_id);
        $this->assertSame(['status' => 'draft', 'completed_at' => null], json_decode($audit->old_values, true));
        $this->assertSame(['status' => 'completed', 'completed_at' => $result->completed_at->toISOString()], json_decode($audit->new_values, true));
        try {
            app(InspectionCompletion::class)->complete($f['owner'], $result->id);
            $this->fail('Repetición aceptada');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inspection', $e->errors());
        }
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.completed')->count());
    }

    public function test_invalid_answers_and_audit_failure_roll_back(): void
    {
        $f = $this->fixture;
        DB::table('inspection_answers')->update(['answer' => 'invalid']);
        try {
            app(InspectionCompletion::class)->complete($f['owner'], $f['inspection']->id);
            $this->fail('Respuesta inválida aceptada');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('answers', $e->errors());
        }
        DB::table('inspection_answers')->update(['answer' => 'false']);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit failure'));
        try {
            app(InspectionCompletion::class)->complete($f['owner'], $f['inspection']->id);
            $this->fail('Fallo de auditoría ocultado');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit failure', $e->getMessage());
        }
        $this->assertSame('draft', $f['inspection']->fresh()->status);
        $this->assertNull($f['inspection']->fresh()->completed_at);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.completed')->count());
    }

    public function test_permission_cache_is_flushed_and_assignment_is_revalidated(): void
    {
        $f = $this->fixture;
        $this->assertTrue($f['owner']->hasPermission(Permission::InspectionsExecute));
        DB::table('role_permissions')->where('role_id', $f['role']->id)->delete();
        try {
            app(InspectionCompletion::class)->complete($f['owner'], $f['inspection']->id);
            $this->fail('Permiso revocado aceptado');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $f['org']->id, 'role_id' => $f['role']->id, 'permission' => Permission::InspectionsExecute->value]);
        DB::table('user_areas')->where('user_id', $f['owner']->id)->delete();
        try {
            app(InspectionCompletion::class)->complete($f['owner'], $f['inspection']->id);
            $this->fail('Asignación revocada aceptada');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('draft', $f['inspection']->fresh()->status);
    }

    public function test_foreign_tenant_and_revoked_membership_are_rejected(): void
    {
        $f = $this->fixture;
        $other = $this->clientOrganization('OTHER');
        app(TenantContext::class)->set($other->id);
        try {
            app(InspectionCompletion::class)->complete($f['owner'], $f['inspection']->id);
            $this->fail('Inspección de otro tenant visible');
        } catch (ModelNotFoundException $e) {
            $this->assertInstanceOf(ModelNotFoundException::class, $e);
        }
        app(TenantContext::class)->set($f['org']->id);
        DB::table('organization_memberships')->where('user_id', $f['owner']->id)->where('organization_id', $f['org']->id)->update(['status' => 'revoked']);
        try {
            app(InspectionCompletion::class)->complete($f['owner'], $f['inspection']->id);
            $this->fail('Membresía revocada aceptada');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('draft', $f['inspection']->fresh()->status);
    }

    public function test_existing_transaction_is_rejected_to_prevent_stale_snapshot(): void
    {
        $f = $this->fixture;
        DB::transaction(function () use ($f): void {
            DB::table('inspection_answers')->count();
            try {
                app(InspectionCompletion::class)->complete($f['owner'], $f['inspection']->id);
                $this->fail('Transacción anidada aceptada');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('propia transacción', $e->getMessage());
            }
        });
        $this->assertSame('draft', $f['inspection']->fresh()->status);
    }

    public function test_first_transactional_query_is_tenant_scoped_inspection_lock(): void
    {
        $f = $this->fixture;
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            if (DB::transactionLevel() > 0) {
                $queries[] = $event;
            }
        });
        app(InspectionCompletion::class)->complete($f['owner'], $f['inspection']->id);
        $this->assertMatchesRegularExpression('/\bfrom\s+[`"]inspections[`"]/i', $queries[0]->sql);
        $this->assertContains($f['org']->id, $queries[0]->bindings);
        $this->assertContains($f['inspection']->id, $queries[0]->bindings);
    }
}
