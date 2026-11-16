<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InspectionAnswerMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Estas pruebas son exclusivas de SQLite :memory:.');
        }
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_19_000001_enforce_inspection_answer_tenant_version_foreign_keys.php');
    }

    private function seedData(): void
    {
        foreach (['1', '2'] as $n) {
            DB::table('organizations')->insert(['id' => "o$n", 'name' => "Org $n", 'code' => "O$n", 'type' => 'client', 'status' => 1]);
            DB::table('plants')->insert(['id' => "p$n", 'organization_id' => "o$n", 'name' => "Plant $n", 'code' => "P$n", 'status' => 1]);
            DB::table('areas')->insert(['id' => "a$n", 'plant_id' => "p$n", 'organization_id' => "o$n", 'name' => "Area $n", 'code' => "A$n", 'status' => 1]);
            DB::table('users')->insert(['id' => "u$n", 'organization_id' => "o$n", 'name' => "User $n", 'email' => "u$n@example.test", 'password' => 'test', 'status' => 1]);
            DB::table('checklists')->insert(['id' => "c$n", 'organization_id' => "o$n", 'name' => "Checklist $n", 'status' => 1]);
            DB::table('checklist_versions')->insert(['id' => "v$n", 'organization_id' => "o$n", 'checklist_id' => "c$n", 'version' => '1', 'created_by' => "u$n", 'status' => 'published', 'published_at' => now()]);
            DB::table('checklist_sections')->insert(['id' => "s$n", 'organization_id' => "o$n", 'checklist_version_id' => "v$n", 'name' => "Section $n", 'display_order' => 1]);
            DB::table('questions')->insert(['id' => "q$n", 'organization_id' => "o$n", 'checklist_section_id' => "s$n", 'question_text' => "Question $n", 'answer_type' => 'text', 'status' => 1, 'display_order' => 1]);
            DB::table('inspections')->insert(['id' => "i$n", 'organization_id' => "o$n", 'user_id' => "u$n", 'checklist_version_id' => "v$n", 'plant_id' => "p$n", 'area_id' => "a$n", 'status' => 'draft']);
        }

        // Segunda versión y sección dentro de la organización 1 para probar
        // preguntas y secciones de otra versión.
        DB::table('checklist_versions')->insert(['id' => 'v1b', 'organization_id' => 'o1', 'checklist_id' => 'c1', 'version' => '2', 'created_by' => 'u1', 'status' => 'draft']);
        DB::table('checklist_sections')->insert(['id' => 's1b', 'organization_id' => 'o1', 'checklist_version_id' => 'v1b', 'name' => 'Section 1b', 'display_order' => 1]);
        DB::table('questions')->insert(['id' => 'q1b', 'organization_id' => 'o1', 'checklist_section_id' => 's1b', 'question_text' => 'Question 1b', 'answer_type' => 'text', 'status' => 1, 'display_order' => 1]);
        DB::table('questions')->insert(['id' => 'q1c', 'organization_id' => 'o1', 'checklist_section_id' => 's1', 'question_text' => 'Question 1c', 'answer_type' => 'text', 'status' => 1, 'display_order' => 2]);
    }

    private function answer(array $overrides = []): array
    {
        return array_replace([
            'id' => 'ans1',
            'organization_id' => 'o1',
            'inspection_id' => 'i1',
            'question_id' => 'q1',
            'answer' => 'texto',
            'observation' => 'obs',
        ], $overrides);
    }

    private function newAnswer(array $overrides = []): array
    {
        return $this->answer(array_replace([
            'checklist_version_id' => 'v1',
            'checklist_section_id' => 's1',
        ], $overrides));
    }

    private function rejects(callable $operation, string $fragment = 'FOREIGN KEY'): void
    {
        try {
            $operation();
            $this->fail('La operación debía ser rechazada por el motor.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($fragment, $exception->getMessage());
        }
    }

    private function healthy(): void
    {
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame('ok', DB::scalar('PRAGMA integrity_check'));
    }

    /**
     * @return list<string>
     */
    private function foreignKeySignatures(string $table): array
    {
        $rows = DB::select('PRAGMA foreign_key_list("'.$table.'")');
        usort($rows, fn ($a, $b) => [$a->id, $a->seq] <=> [$b->id, $b->seq]);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->id]['table'] = $row->table;
            $grouped[$row->id]['from'][] = $row->from;
            $grouped[$row->id]['to'][] = $row->to;
            $grouped[$row->id]['delete'] = $row->on_delete;
            $grouped[$row->id]['update'] = $row->on_update;
        }
        $signatures = [];
        foreach ($grouped as $group) {
            $signatures[] = sprintf('%s:%s->%s:%s/%s', $group['table'], implode(',', $group['from']), implode(',', $group['to']), $group['delete'], $group['update']);
        }
        sort($signatures);

        return $signatures;
    }

    public function test_migracion_desde_cero_define_columnas_indices_y_fk(): void
    {
        $columns = array_map(fn ($column) => $column->name, DB::select('PRAGMA table_xinfo("inspection_answers")'));
        $this->assertSame([
            'id', 'inspection_id', 'question_id', 'answer', 'observation',
            'created_at', 'updated_at', 'organization_id',
            'checklist_version_id', 'checklist_section_id',
        ], $columns);

        $foreignKeys = collect(DB::select('PRAGMA foreign_key_list("inspection_answers")'));
        $this->assertTrue($foreignKeys->contains(fn ($fk) => $fk->table === 'inspections'
            && $fk->from === 'inspection_id' && $fk->to === 'id'
            && $fk->on_delete === 'CASCADE' && $fk->on_update === 'CASCADE'));
        $this->assertTrue($foreignKeys->contains(fn ($fk) => $fk->table === 'checklist_sections'
            && $fk->from === 'checklist_section_id' && $fk->to === 'id'
            && $fk->on_delete === 'RESTRICT' && $fk->on_update === 'CASCADE'));
        $this->assertTrue($foreignKeys->contains(fn ($fk) => $fk->table === 'questions'
            && $fk->from === 'question_id' && $fk->to === 'id'
            && $fk->on_delete === 'RESTRICT' && $fk->on_update === 'CASCADE'));

        // Los grupos compuestos se reconocen por sus columnas de origen.
        $composites = $foreignKeys->groupBy('id')->map(fn ($group) => $group->sortBy('seq')->pluck('from')->implode(','))->values();
        $this->assertTrue($composites->contains('inspection_id,checklist_version_id,organization_id'));
        $this->assertTrue($composites->contains('checklist_section_id,checklist_version_id,organization_id'));
        $this->assertTrue($composites->contains('question_id,checklist_section_id,organization_id'));

        foreach ([
            ['inspections', 'ins_answers_insp_version_org_unique', ['id', 'checklist_version_id', 'organization_id']],
            ['checklist_sections', 'ins_answers_sect_version_org_unique', ['id', 'checklist_version_id', 'organization_id']],
            ['questions', 'ins_answers_quest_sect_org_unique', ['id', 'checklist_section_id', 'organization_id']],
        ] as [$table, $index, $fields]) {
            $info = DB::select('PRAGMA index_info("'.$index.'")');
            $this->assertSame($fields, array_map(fn ($row) => $row->name, $info));
            $this->assertSame(1, (int) DB::scalar('SELECT COUNT(*) FROM sqlite_master WHERE type = "index" AND tbl_name = "'.$table.'" AND name = "'.$index.'"'));
        }

        $this->assertSame(1, (int) DB::scalar('SELECT COUNT(*) FROM sqlite_master WHERE type = "index" AND tbl_name = "inspection_answers" AND name = "inspection_answers_inspection_question_unique"'));
        $this->assertSame(1, (int) DB::scalar('SELECT COUNT(*) FROM sqlite_master WHERE type = "index" AND tbl_name = "inspection_answers" AND name = "inspection_answers_organization_idx"'));
        $this->assertContains('inspection_answers:inspection_answer_id->id:RESTRICT/CASCADE', $this->foreignKeySignatures('evidences'));
        $this->healthy();
    }

    public function test_up_down_up_conserva_datos_backfill_y_evidencias(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();
        DB::table('inspection_answers')->insert($this->answer());
        DB::table('inspection_answers')->insert($this->answer(['id' => 'ans2', 'organization_id' => 'o2', 'inspection_id' => 'i2', 'question_id' => 'q2']));
        DB::table('evidences')->insert(['id' => 'e1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'inspection_answer_id' => 'ans1', 'uploaded_by' => 'u1', 'file_name' => 'f.jpg', 'storage_path' => 'p/f.jpg']);
        DB::statement('CREATE INDEX "extra_answer_idx" ON "inspection_answers" ("answer")');

        $oldAnswers = DB::table('inspection_answers')->orderBy('id')->get()->toArray();
        $evidences = DB::table('evidences')->get()->toArray();
        $extra = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND name = 'extra_answer_idx'");

        foreach (['up', 'down', 'up'] as $direction) {
            $migration->$direction();
            $rows = DB::table('inspection_answers')->orderBy('id')->get()->toArray();
            $this->assertCount(2, $rows);
            foreach ($oldAnswers as $index => $old) {
                foreach (['id', 'organization_id', 'inspection_id', 'question_id', 'answer', 'observation'] as $field) {
                    $this->assertSame($old->{$field}, $rows[$index]->{$field});
                }
            }
            if ($direction === 'down') {
                $this->assertFalse(property_exists($rows[0], 'checklist_version_id'));
            } else {
                $this->assertSame('v1', $rows[0]->checklist_version_id);
                $this->assertSame('s1', $rows[0]->checklist_section_id);
                $this->assertSame('v2', $rows[1]->checklist_version_id);
                $this->assertSame('s2', $rows[1]->checklist_section_id);
            }
            $this->assertEquals($evidences, DB::table('evidences')->get()->toArray());
            $this->assertEquals($extra, DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND name = 'extra_answer_idx'"));
            $this->healthy();
        }
    }

    public function test_fk_compuestas_rechazan_cruces_duplicados_y_borrados_invalidos(): void
    {
        $this->seedData();
        DB::table('inspection_answers')->insert($this->newAnswer());

        // Duplicado funcional.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer(['id' => 'ansdup', 'answer' => 'otro'])), 'UNIQUE');

        // Pregunta de otra organización.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'cross1', 'question_id' => 'q2', 'checklist_version_id' => 'v2', 'checklist_section_id' => 's2',
        ])));

        // Pregunta de otra versión de la misma organización.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'cross2', 'question_id' => 'q1b', 'checklist_version_id' => 'v1', 'checklist_section_id' => 's1',
        ])));

        // Sección que no corresponde a la pregunta.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'cross3', 'question_id' => 'q1c', 'checklist_version_id' => 'v1', 'checklist_section_id' => 's1b',
        ])));

        // Versión que no corresponde a la inspección.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'cross4', 'question_id' => 'q1b', 'checklist_version_id' => 'v1b', 'checklist_section_id' => 's1b',
        ])));

        // Inspección de otra organización.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'cross5', 'inspection_id' => 'i2',
        ])));

        // Eliminar pregunta o sección referenciada queda restringido.
        $this->rejects(fn () => DB::table('questions')->where('id', 'q1')->delete(), 'FOREIGN KEY');
        $this->rejects(fn () => DB::table('checklist_sections')->where('id', 's1')->delete(), 'FOREIGN KEY');

        // Eliminar la inspección arrastra sus respuestas (CASCADE) cuando no
        // hay evidencias que lo impidan.
        DB::table('inspections')->where('id', 'i1')->delete();
        $this->assertSame(0, DB::table('inspection_answers')->where('inspection_id', 'i1')->count());
        $this->healthy();
    }

    public function test_preflight_rechaza_inconsistencias_sin_reparar_ni_modificar_esquema(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();

        $invalid = [
            // Pregunta de otra organización.
            $this->answer(['id' => 'bad1', 'question_id' => 'q2']),
            // Inspección de otra organización.
            $this->answer(['id' => 'bad2', 'inspection_id' => 'i2']),
            // Pregunta de una versión distinta a la inspección.
            $this->answer(['id' => 'bad3', 'question_id' => 'q1b']),
        ];

        foreach ($invalid as $row) {
            DB::table('inspection_answers')->insert($row);
            $schema = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');
            $rows = DB::table('inspection_answers')->orderBy('id')->get()->toArray();
            try {
                $migration->up();
                $this->fail('El preflight debía rechazar la respuesta inconsistente.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('huérfanas', $exception->getMessage());
            }
            $this->assertEquals($schema, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
            $this->assertEquals($rows, DB::table('inspection_answers')->orderBy('id')->get()->toArray());
            $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
            $this->healthy();
            DB::table('inspection_answers')->where('id', $row['id'])->delete();
        }
    }

    public function test_estado_parcial_y_objetos_inesperados_se_rechazan(): void
    {
        $migration = $this->migration();
        $migration->down();

        // Columna parcial.
        DB::statement('ALTER TABLE "inspection_answers" ADD COLUMN "checklist_version_id" varchar');
        try {
            $migration->up();
            $this->fail('Las columnas parciales debían rechazarse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('columnas inesperadas', $exception->getMessage());
        }
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->healthy();
    }

    public function test_indice_requerido_con_definicion_inesperada_se_rechaza(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement('DROP INDEX "inspection_answers_organization_idx"');
        DB::statement('CREATE INDEX "inspection_answers_organization_idx" ON "inspection_answers" ("answer")');
        try {
            $migration->up();
            $this->fail('Un índice requerido con definición inesperada debía rechazarse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('índices', $exception->getMessage());
        }
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->healthy();
    }

    public function test_fallo_durante_reconstruccion_revierte_ddl_y_restaura_pragma(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();
        DB::table('inspection_answers')->insert($this->answer());
        $before = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');
        $rows = DB::table('inspection_answers')->get()->toArray();
        $active = true;
        DB::listen(function ($query) use (&$active): void {
            if ($active && $query->sql === 'DROP TABLE "inspection_answers"') {
                $active = false;
                throw new \RuntimeException('fallo inducido');
            }
        });
        try {
            $migration->up();
            $this->fail('Se esperaba el fallo inducido.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('fallo inducido', $exception->getMessage());
        } finally {
            $active = false;
        }
        $this->assertEquals($before, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
        $this->assertEquals($rows, DB::table('inspection_answers')->get()->toArray());
        $this->healthy();
    }
}
