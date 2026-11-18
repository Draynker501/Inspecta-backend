<?php

namespace Tests\Feature\Inspections;

use App\Authorization\Permission;
use App\Models\AuditLog;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Inspection;
use App\Models\Question;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Inspections\InspectionReview;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class InspectionReviewTest extends TestCase
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

    public function test_confirm_is_audited_and_preserves_content(): void
    {
        $f = $this->fixture;
        $before = DB::table('inspection_answers')->get()->toArray();
        $this->travelTo(now()->startOfSecond());
        $result = app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, ['action' => 'confirm']);
        $this->assertSame('confirmed', $result->status);
        $this->assertSame($f['reviewer']->id, $result->supervisor_id);
        $this->assertTrue($result->supervisor_confirmed_at->equalTo(now()));
        $this->assertNull($result->rejected_at);
        $this->assertNull($result->supervisor_observations);
        $this->assertEquals($f['inspection']->completed_at, $result->completed_at);
        $this->assertEquals($f['inspection']->started_at, $result->started_at);
        $this->assertEquals($before, DB::table('inspection_answers')->get()->toArray());
        $audit = DB::table('audit_logs')->where('action', 'inspection.confirmed')->sole();
        $this->assertSame($f['reviewer']->id, $audit->user_id);
        $this->assertSame($f['org']->id, $audit->organization_id);
        $this->assertSame($result->id, $audit->auditable_id);
        $this->assertSame(['status' => 'completed'], json_decode($audit->old_values, true));
        $this->assertSame(['status' => 'confirmed', 'supervisor_confirmed_at' => $result->supervisor_confirmed_at->toISOString()], json_decode($audit->new_values, true));
    }

    public function test_rejection_keeps_original_reason_and_repeat_has_no_audit(): void
    {
        $f = $this->fixture;
        $reason = '  motivo privado ñ  ';
        $result = app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, ['action' => 'reject', 'supervisor_observations' => $reason]);
        $this->assertSame('rejected', $result->status);
        $this->assertSame($reason, $result->supervisor_observations);
        $this->assertNull($result->supervisor_confirmed_at);
        $this->assertNotNull($result->rejected_at);
        $audit = DB::table('audit_logs')->where('action', 'inspection.rejected')->sole();
        $this->assertStringNotContainsString($reason, $audit->new_values);
        $this->assertSame(['status' => 'rejected', 'rejected_at' => $result->rejected_at->toISOString()], json_decode($audit->new_values, true));
        try {
            app(InspectionReview::class)->review($f['reviewer'], $result->id, ['action' => 'confirm']);
            $this->fail('Repetición aceptada');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inspection', $e->errors());
        }
        $this->assertSame(1, DB::table('audit_logs')->whereIn('action', ['inspection.confirmed', 'inspection.rejected'])->count());
        $this->assertSame($reason, $result->fresh()->supervisor_observations);
    }

    public function test_invalid_inputs_and_inconsistent_metadata_are_rejected(): void
    {
        $f = $this->fixture;
        foreach ([
            [], ['action' => 'confirm', 'supervisor_observations' => null],
            ['action' => 'confirm', 'extra' => 1], ['action' => 'reject'],
            ['action' => 'reject', 'supervisor_observations' => null],
            ['action' => 'reject', 'supervisor_observations' => " \t\u{00a0}"],
            ['action' => 'reject', 'supervisor_observations' => "\u{0085}"],
            ['action' => 'reject', 'supervisor_observations' => "\u{2028}"],
            ['action' => 'reject', 'supervisor_observations' => str_repeat('ñ', 32768)],
            ['action' => 'reject', 'supervisor_observations' => "\xff"],
        ] as $input) {
            try {
                app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, $input);
                $this->fail('Entrada inválida aceptada');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('fields', $e->errors());
            }
        }
        foreach (['supervisor_id' => $f['reviewer']->id, 'supervisor_confirmed_at' => now(), 'rejected_at' => now(), 'supervisor_observations' => 'previo'] as $field => $value) {
            DB::table('inspections')->where('id', $f['inspection']->id)->update([$field => $value]);
            try {
                app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, ['action' => 'confirm']);
                $this->fail('Metadato incoherente aceptado');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('inspection', $e->errors());
            }
            DB::table('inspections')->where('id', $f['inspection']->id)->update([$field => null]);
        }
        $this->assertSame(0, DB::table('audit_logs')->whereIn('action', ['inspection.confirmed', 'inspection.rejected'])->count());
    }

    public function test_revocations_tenant_and_self_review_fail_closed(): void
    {
        $f = $this->fixture;
        $this->assertTrue($f['reviewer']->hasPermission(Permission::InspectionsManage));
        DB::table('role_permissions')->where('role_id', $f['reviewRole']->id)->delete();
        foreach ([$f['reviewer'], $f['owner']] as $actor) {
            try {
                app(InspectionReview::class)->review($actor, $f['inspection']->id, ['action' => 'confirm']);
                $this->fail('Autorización inválida aceptada');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $other = $this->clientOrganization('OTHER04');
        app(TenantContext::class)->set($other->id);
        try {
            app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, ['action' => 'confirm']);
            $this->fail('Tenant ajeno aceptado');
        } catch (ModelNotFoundException $e) {
            $this->assertInstanceOf(ModelNotFoundException::class, $e);
        }
        app(TenantContext::class)->set($f['org']->id);
        $this->assertSame('completed', $f['inspection']->fresh()->status);
    }

    public function test_audit_failure_rolls_back_and_nested_transaction_is_forbidden(): void
    {
        $f = $this->fixture;
        DB::transaction(function () use ($f): void {
            try {
                app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, ['action' => 'confirm']);
                $this->fail('Transacción anidada aceptada');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('propia transacción', $e->getMessage());
            }
        });
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit failure'));
        try {
            app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, ['action' => 'reject', 'supervisor_observations' => 'motivo']);
            $this->fail('Fallo de auditoría ocultado');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit failure', $e->getMessage());
        }
        $result = $f['inspection']->fresh();
        $this->assertSame('completed', $result->status);
        $this->assertNull($result->supervisor_id);
        $this->assertNull($result->rejected_at);
        $this->assertNull($result->supervisor_observations);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.rejected')->count());
    }

    public function test_failure_after_audit_insert_rolls_back_everything(): void
    {
        $f = $this->fixture;
        $inserted = false;
        Event::listen('eloquent.created: '.AuditLog::class, function () use (&$inserted): void {
            $inserted = DB::table('audit_logs')->where('action', 'inspection.rejected')->exists();
            throw new \RuntimeException('after audit insert');
        });
        try {
            try {
                app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, ['action' => 'reject', 'supervisor_observations' => 'motivo']);
                $this->fail('Fallo de auditoría ocultado');
            } catch (\RuntimeException $e) {
                $this->assertSame('after audit insert', $e->getMessage());
            }
        } finally {
            Event::forget('eloquent.created: '.AuditLog::class);
        }
        $this->assertTrue($inserted);
        $result = $f['inspection']->fresh();
        $this->assertSame('completed', $result->status);
        $this->assertNull($result->supervisor_id);
        $this->assertNull($result->supervisor_confirmed_at);
        $this->assertNull($result->rejected_at);
        $this->assertNull($result->supervisor_observations);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.rejected')->count());
    }

    public function test_revalidation_of_assignment_membership_and_actor_status(): void
    {
        $f = $this->fixture;
        $review = fn () => app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, ['action' => 'confirm']);
        $assertDenied = function () use ($review): void {
            try {
                $review();
                $this->fail('Revocación ignorada');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        };
        DB::table('user_areas')->where('user_id', $f['reviewer']->id)->delete();
        $assertDenied();
        DB::table('user_areas')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $f['org']->id, 'user_id' => $f['reviewer']->id, 'area_id' => $f['inspection']->area_id]);
        DB::table('organization_memberships')->where('user_id', $f['reviewer']->id)->where('organization_id', $f['org']->id)->update(['status' => 'revoked']);
        $assertDenied();
        DB::table('organization_memberships')->where('user_id', $f['reviewer']->id)->where('organization_id', $f['org']->id)->update(['status' => 'active']);
        DB::table('users')->where('id', $f['reviewer']->id)->update(['status' => false]);
        $assertDenied();
        $this->assertSame('completed', $f['inspection']->fresh()->status);
    }

    public function test_self_review_is_denied_even_with_manage_and_assignment(): void
    {
        $f = $this->fixture;
        DB::table('inspections')->where('id', $f['inspection']->id)->update(['user_id' => $f['reviewer']->id]);
        try {
            app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, ['action' => 'confirm']);
            $this->fail('Autoevaluación aceptada');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('completed', $f['inspection']->fresh()->status);
    }

    public function test_maximum_reason_is_measured_in_bytes_and_preserved(): void
    {
        $f = $this->fixture;
        $reason = str_repeat('ñ', 32767).'x';
        $result = app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, ['action' => 'reject', 'supervisor_observations' => $reason]);
        $this->assertSame(65535, strlen($result->fresh()->supervisor_observations));
        $this->assertSame($reason, $result->fresh()->supervisor_observations);
    }

    public function test_first_query_locks_tenant_scoped_inspection_and_other_states_are_rejected(): void
    {
        $f = $this->fixture;
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            if (DB::transactionLevel() > 0) {
                $queries[] = $event;
            }
        });
        DB::table('inspections')->where('id', $f['inspection']->id)->update(['status' => 'draft']);
        try {
            app(InspectionReview::class)->review($f['reviewer'], $f['inspection']->id, ['action' => 'confirm']);
            $this->fail('Borrador aceptado');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inspection', $e->errors());
        }
        $this->assertMatchesRegularExpression('/\bfrom\s+[`"]inspections[`"]/i', $queries[0]->sql);
        $this->assertContains($f['org']->id, $queries[0]->bindings);
        $this->assertContains($f['inspection']->id, $queries[0]->bindings);
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->assertStringContainsString('for update', strtolower($queries[0]->sql));
        }
    }
}
