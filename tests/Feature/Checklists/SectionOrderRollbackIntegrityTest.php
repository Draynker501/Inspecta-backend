<?php

namespace Tests\Feature\Checklists;

use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Question;
use App\Support\Ehs\SqliteOrderRebuild;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class SectionOrderRollbackIntegrityTest extends TestCase
{
    use TenantFixtures;

    public function test_section_order_rollback_and_reapply_preserve_questions_and_foreign_keys(): void
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Solo se permite SQLite en memoria para esta prueba de migración.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        $org = $this->organization('EHS-ROLLBACK');
        $user = $this->user($org);
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'C', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'version' => '1', 'status' => 'draft', 'created_by' => $user->id]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1]);
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Q', 'answer_type' => 'boolean', 'display_order' => 1]);
        $beforeSection = (array) $db->table('checklist_sections')->where('id', $section->id)->first();
        $beforeQuestion = (array) $db->table('questions')->where('id', $question->id)->first();
        $beforeFks = $db->select('PRAGMA foreign_key_list("checklist_sections")');
        $beforeIndexes = $db->select('PRAGMA index_list("checklist_sections")');
        $questionFks = $db->select('PRAGMA foreign_key_list("questions")');
        $migration = require database_path('migrations/2026_11_12_003631_add_unique_display_order_to_checklist_sections_table.php');

        foreach (['down', 'up'] as $direction) {
            $migration->$direction();
            $this->assertSame($beforeSection, (array) $db->table('checklist_sections')->where('id', $section->id)->first());
            $this->assertSame($beforeQuestion, (array) $db->table('questions')->where('id', $question->id)->first());
            $this->assertEquals($beforeFks, $db->select('PRAGMA foreign_key_list("checklist_sections")'));
            $this->assertEquals($questionFks, $db->select('PRAGMA foreign_key_list("questions")'));
            $this->assertSame(1, (int) $db->scalar('PRAGMA foreign_keys'));
            $this->assertSame([], $db->select('PRAGMA foreign_key_check'));
            $this->assertSame('ok', $db->scalar('PRAGMA integrity_check'));
        }
        // La comparación es semántica (el orden físico de `PRAGMA index_list`
        // depende del motor y de las migraciones aplicadas), pero conserva la
        // garantía original: mismos índices, unicidad, origen, parcialidad y
        // las mismas columnas en cada índice.
        $indexSignature = static function (array $rows) use ($db): array {
            return collect($rows)
                ->map(static function (object $row) use ($db): array {
                    $quoted = '"'.str_replace('"', '""', (string) $row->name).'"';

                    return [
                        'name' => $row->name,
                        'unique' => (int) $row->unique,
                        'origin' => $row->origin,
                        'partial' => (int) $row->partial,
                        'columns' => collect($db->select('PRAGMA index_info('.$quoted.')'))
                            ->map(static fn (object $column): string => (string) $column->name)
                            ->all(),
                    ];
                })
                ->sortBy('name')
                ->values()
                ->all();
        };
        $this->assertEquals(
            $indexSignature($beforeIndexes),
            $indexSignature($db->select('PRAGMA index_list("checklist_sections")')),
        );

        $changes = [];
        foreach (['checklist_sections' => 'sections_display_order_positive', 'questions' => 'questions_display_order_positive'] as $table => $name) {
            $sql = $db->scalar("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
            if (str_contains(strtolower($sql), strtolower($name))) {
                $changes[] = ['table' => $table, 'check' => 'drop'];
            }
        }
        if ($changes !== []) {
            SqliteOrderRebuild::run($db, $changes);
        }
        $changes = [['table' => 'checklist_sections', 'check' => 'add'], ['table' => 'questions', 'check' => 'add']];
        SqliteOrderRebuild::run($db, $changes);
        foreach ([['checklist_sections', $section->id], ['questions', $question->id]] as [$table, $id]) {
            foreach ([0, -1] as $invalid) {
                try {
                    $db->table($table)->where('id', $id)->update(['display_order' => $invalid]);
                    $this->fail('SQL directo debe rechazar órdenes no positivos.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('CHECK', strtoupper($exception->getMessage()));
                }
            }
        }
        $this->assertSame($beforeQuestion, (array) $db->table('questions')->where('id', $question->id)->first());
        $this->assertSame([], $db->select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) $db->scalar('PRAGMA foreign_keys'));
    }

    public function test_check_preflight_rechaza_datos_invalidos_sin_modificarlos(): void
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Solo SQLite en memoria.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $org = $this->organization('EHS-CHECK-PREFLIGHT');
        $user = $this->user($org);
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'C', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'version' => '1', 'status' => 'draft', 'created_by' => $user->id]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1]);
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Q', 'answer_type' => 'boolean', 'display_order' => 1]);
        $migration = require database_path('migrations/2026_11_16_000001_add_positive_display_order_checks.php');
        $migration->down();
        foreach ([['checklist_sections', $section->id], ['questions', $question->id]] as [$table, $id]) {
            $db->table($table)->where('id', $id)->update(['display_order' => 0]);
            try {
                $migration->up();
                $this->fail('El preflight debe rechazar órdenes históricos inválidos.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('menores que 1', $exception->getMessage());
                $this->assertSame(0, $db->table($table)->where('id', $id)->value('display_order'));
                $this->assertSame(1, (int) $db->scalar('PRAGMA foreign_keys'));
                $this->assertSame([], $db->select('PRAGMA foreign_key_check'));
            }
            $db->table($table)->where('id', $id)->update(['display_order' => 1]);
        }
        $migration->up();
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('questions', ['id' => $question->id, 'checklist_section_id' => $section->id]);
        $this->assertSame([], $db->select('PRAGMA foreign_key_check'));
    }

    public function test_rebuild_rejects_an_external_transaction_without_changing_pragma(): void
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Solo SQLite en memoria.');
        }
        $db->statement('PRAGMA foreign_keys = ON');
        $db->beginTransaction();
        try {
            try {
                SqliteOrderRebuild::run($db, [['table' => 'checklist_sections', 'check' => 'add']]);
                $this->fail('Debe rechazar la transacción externa.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('transacción externa', $exception->getMessage());
                $this->assertSame(1, (int) $db->scalar('PRAGMA foreign_keys'));
            }
        } finally {
            $db->rollBack();
        }
    }
}
