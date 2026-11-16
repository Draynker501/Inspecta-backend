<?php

namespace Tests\Feature\Checklists;

use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Question;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ChecklistMigrationTest extends TestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Estas pruebas reconstruyen exclusivamente SQLite en memoria.');
        }

        // La reconstrucción exige estar fuera de transacciones. Para probar las
        // migraciones originales, se vuelve primero al estado anterior al CHECK.
        Artisan::call('migrate:fresh', ['--force' => true]);
        (require database_path('migrations/2026_11_16_000001_add_positive_display_order_checks.php'))->down();
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:') {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        parent::tearDown();
    }

    public function test_los_indices_de_orden_se_revierten_y_reaplican_con_tablas_vacias(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Verificación de migraciones aislada en SQLite.');
        }
        foreach ([
            ['checklist_sections', 'sections_version_order_unique', '2026_11_12_003631_add_unique_display_order_to_checklist_sections_table.php'],
            ['questions', 'questions_section_order_unique', '2026_11_12_003625_add_unique_display_order_to_questions_table.php'],
        ] as [$table, $index, $file]) {
            $migration = require database_path('migrations/'.$file);
            $migration->down();
            $this->assertFalse($this->hasIndex($table, $index));
            $migration->up();
            $this->assertTrue($this->hasIndex($table, $index));
        }
    }

    public function test_preflight_rechaza_duplicados_y_ceros_sin_repararlos(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Verificación de migraciones aislada en SQLite.');
        }
        $sectionMigration = require database_path('migrations/2026_11_12_003631_add_unique_display_order_to_checklist_sections_table.php');
        $questionMigration = require database_path('migrations/2026_11_12_003625_add_unique_display_order_to_questions_table.php');
        $questionMigration->down();
        $sectionMigration->down();
        $org = $this->organization('MIG-PREFLIGHT');
        $user = $this->user($org);
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'C', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'version' => '1', 'status' => 'draft', 'created_by' => $user->id]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1]);
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Q', 'answer_type' => 'boolean', 'display_order' => 1]);

        foreach ([['checklist_sections', $section->id, $sectionMigration], ['questions', $question->id, $questionMigration]] as [$table, $id, $migration]) {
            $row = (array) DB::table($table)->where('id', $id)->first();
            $duplicate = (string) Str::uuid7();
            $row['id'] = $duplicate;
            DB::table($table)->insert($row);
            try {
                $migration->up();
                $this->fail('Debió rechazar duplicados.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('repetido', $exception->getMessage());
                $this->assertSame(2, DB::table($table)->where('display_order', 1)->count());
            }
            DB::table($table)->where('id', $duplicate)->delete();
            DB::table($table)->where('id', $id)->update(['display_order' => 0]);
            try {
                $migration->up();
                $this->fail('Debió rechazar el orden cero.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('menor que 1', $exception->getMessage());
                $this->assertSame(0, DB::table($table)->where('id', $id)->value('display_order'));
            }
            DB::table($table)->where('id', $id)->update(['display_order' => 1]);
        }
    }

    public function test_migraciones_de_orden_rechazan_datos_invalidos_y_son_reversibles(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Verificación de migraciones aislada en SQLite.');
        }
        $org = $this->organization('MIG-CHECK');
        $user = $this->user($org);
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'C', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'version' => '1', 'status' => 'draft', 'created_by' => $user->id]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1]);
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Q', 'answer_type' => 'boolean', 'display_order' => 1]);

        foreach ([
            ['checklist_sections', $section->id, 'sections_version_order_unique', '2026_11_12_003631_add_unique_display_order_to_checklist_sections_table.php'],
            ['questions', $question->id, 'questions_section_order_unique', '2026_11_12_003625_add_unique_display_order_to_questions_table.php'],
        ] as [$table, $id, $index, $file]) {
            $migration = require database_path('migrations/'.$file);
            $migration->down();
            $this->assertFalse($this->hasIndex($table, $index));
            $row = (array) DB::table($table)->where('id', $id)->first();
            $duplicateId = (string) Str::uuid7();
            $row['id'] = $duplicateId;
            DB::table($table)->insert($row);

            try {
                $migration->up();
                $this->fail('La migración debe rechazar órdenes duplicados.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('repetido', $exception->getMessage());
                $this->assertSame(2, DB::table($table)->where('display_order', 1)->count());
            }
            DB::table($table)->where('id', $duplicateId)->delete();
            DB::table($table)->where('id', $id)->update(['display_order' => 0]);
            try {
                $migration->up();
                $this->fail('La migración debe rechazar órdenes menores que uno.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('menor que 1', $exception->getMessage());
                $this->assertSame(0, DB::table($table)->where('id', $id)->value('display_order'));
            }
            DB::table($table)->where('id', $id)->update(['display_order' => 1]);
            $migration->up();
            $this->assertTrue($this->hasIndex($table, $index));
        }
    }

    public function test_columna_options_se_puede_revertir_y_reaplicar(): void
    {
        $migration = require database_path('migrations/2026_11_11_061742_add_options_to_questions_table.php');
        $this->assertTrue(Schema::hasColumn('questions', 'options'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('questions', 'options'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('questions', 'options'));
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(DB::select('PRAGMA index_list('.$table.')'))->contains(fn ($index) => $index->name === $name);
    }
}
