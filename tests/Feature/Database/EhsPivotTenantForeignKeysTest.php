<?php

namespace Tests\Feature\Database;

use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\Question;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class EhsPivotTenantForeignKeysTest extends TestCase
{
    use TenantFixtures;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_dec17_rechaza_sql_cross_tenant_y_preserva_datos_up_down_up(): void
    {
        $db = DB::connection();
        $this->assertTrue($db->getDriverName() === 'sqlite' ? $db->getDatabaseName() === ':memory:' : str_ends_with($db->getDatabaseName(), '_test'));
        Artisan::call('migrate:fresh', ['--force' => true]);
        $org = $this->organization('DEC17-A');
        $other = $this->organization('DEC17-B');
        $user = $this->user($org);
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'DEC17', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'version' => '1', 'status' => 'draft', 'created_by' => $user->id]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1]);
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Q', 'answer_type' => 'boolean', 'display_order' => 1]);
        $norm = Norm::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'code' => 'DEC17-A', 'name' => 'A', 'status' => true]);
        $foreignNorm = Norm::create(['id' => (string) Str::uuid7(), 'organization_id' => $other->id, 'code' => 'DEC17-B', 'name' => 'B', 'status' => true]);
        $requirement = NormRequirement::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'norm_id' => $norm->id, 'code' => 'R', 'description' => 'Local']);
        $foreign = NormRequirement::create(['id' => (string) Str::uuid7(), 'organization_id' => $other->id, 'norm_id' => $foreignNorm->id, 'code' => 'R', 'description' => 'Ajeno']);
        $valid = (string) Str::uuid7();
        $db->table('question_norm_requirement')->insert(['id' => $valid, 'organization_id' => $org->id, 'question_id' => $question->id, 'norm_requirement_id' => $requirement->id]);
        $migration = require database_path('migrations/2026_11_17_000001_enforce_ehs_pivot_tenant_foreign_keys.php');

        foreach (['down', 'up', 'down', 'up'] as $direction) {
            $migration->$direction();
            $this->assertDatabaseHas('question_norm_requirement', ['id' => $valid, 'organization_id' => $org->id]);
            if ($direction === 'up') {
                foreach ([
                    ['question_id' => $question->id, 'norm_requirement_id' => $foreign->id],
                    ['question_id' => $question->id, 'norm_requirement_id' => $requirement->id, 'organization_id' => $other->id],
                ] as $invalid) {
                    try {
                        $db->table('question_norm_requirement')->insert(array_merge([
                            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
                        ], $invalid));
                        $this->fail('SQL directo debe rechazar asociaciones cross-tenant.');
                    } catch (QueryException) {
                        $this->assertSame(1, $db->table('question_norm_requirement')->count());
                    }
                }
            }
        }
        if ($db->getDriverName() === 'sqlite') {
            $this->assertSame([], $db->select('PRAGMA foreign_key_check'));
            $this->assertSame(1, (int) $db->scalar('PRAGMA foreign_keys'));
        }
    }

    public function test_dec17_preflight_no_repara_vinculo_cross_tenant(): void
    {
        $db = DB::connection();
        $this->assertTrue($db->getDriverName() === 'sqlite' ? $db->getDatabaseName() === ':memory:' : str_ends_with($db->getDatabaseName(), '_test'));
        Artisan::call('migrate:fresh', ['--force' => true]);
        $a = $this->organization('DEC17-P');
        $b = $this->organization('DEC17-Q');
        $user = $this->user($a);
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $a->id, 'name' => 'C', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $a->id, 'checklist_id' => $checklist->id, 'version' => '1', 'status' => 'draft', 'created_by' => $user->id]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $a->id, 'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1]);
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $a->id, 'checklist_section_id' => $section->id, 'question_text' => 'Q', 'answer_type' => 'boolean', 'display_order' => 1]);
        $norm = Norm::create(['id' => (string) Str::uuid7(), 'organization_id' => $b->id, 'code' => 'X', 'name' => 'X', 'status' => true]);
        $requirement = NormRequirement::create(['id' => (string) Str::uuid7(), 'organization_id' => $b->id, 'norm_id' => $norm->id, 'code' => 'R', 'description' => 'X']);
        $migration = require database_path('migrations/2026_11_17_000001_enforce_ehs_pivot_tenant_foreign_keys.php');
        $migration->down();
        $id = (string) Str::uuid7();
        $db->table('question_norm_requirement')->insert(['id' => $id, 'organization_id' => $a->id, 'question_id' => $question->id, 'norm_requirement_id' => $requirement->id]);
        try {
            $migration->up();
            $this->fail('El preflight debe impedir el DDL.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('pivote', $exception->getMessage());
        }
        $this->assertDatabaseHas('question_norm_requirement', ['id' => $id, 'norm_requirement_id' => $requirement->id]);
        $db->table('question_norm_requirement')->where('id', $id)->delete();
        $migration->up();
    }
}
