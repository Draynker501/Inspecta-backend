<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Integridad referencial de `inspection_answers` con SQL directo en MariaDB.
 *
 * Cubre las acciones referenciales históricas (CASCADE en eliminación de la
 * inspección, RESTRICT en pregunta y sección, CASCADE en actualización del
 * identificador de la inspección) y la interacción con las columnas
 * compartidas por las tres FK compuestas de INS-02.
 */
class InspectionAnswerMariaDbTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Solo MariaDB aislada.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->assertStringEndsWith('_test', DB::getDatabaseName());
        $this->assertMatchesRegularExpression('/11\.8\.\d+.*MariaDB/i', DB::scalar('SELECT VERSION()'));
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
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

    private function rejects(callable $operation, string $fragment = 'foreign key constraint'): void
    {
        try {
            $operation();
            $this->fail('La operación debía ser rechazada por el motor.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
            $this->assertStringContainsString($fragment, $exception->getMessage());
        }
    }

    private function index(string $table, string $name): array
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->orderBy('SEQ_IN_INDEX')
            ->get()
            ->all();
    }

    private function foreignKey(string $table, string $name): array
    {
        return DB::select(
            'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE '
            .'FROM information_schema.KEY_COLUMN_USAGE k '
            .'JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
            .'AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
            .'WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ? ORDER BY k.ORDINAL_POSITION',
            [DB::getDatabaseName(), $table, $name],
        );
    }

    public function test_esquema_columnas_indices_y_fk_compuestas(): void
    {
        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'inspection_answers')
            ->orderBy('ORDINAL_POSITION')
            ->get();
        $this->assertSame([
            'id', 'organization_id', 'inspection_id', 'question_id', 'answer',
            'observation', 'created_at', 'updated_at', 'checklist_version_id', 'checklist_section_id',
        ], $columns->pluck('COLUMN_NAME')->all());
        foreach (['checklist_version_id', 'checklist_section_id'] as $column) {
            $row = $columns->firstWhere('COLUMN_NAME', $column);
            $this->assertSame('char(36)', $row->COLUMN_TYPE);
            $this->assertSame('NO', $row->IS_NULLABLE);
        }

        foreach ([
            ['inspections', 'ins_answers_insp_version_org_unique', ['id', 'checklist_version_id', 'organization_id']],
            ['checklist_sections', 'ins_answers_sect_version_org_unique', ['id', 'checklist_version_id', 'organization_id']],
            ['questions', 'ins_answers_quest_sect_org_unique', ['id', 'checklist_section_id', 'organization_id']],
            ['inspection_answers', 'ins_answers_insp_version_org_idx', ['inspection_id', 'checklist_version_id', 'organization_id']],
            ['inspection_answers', 'ins_answers_sect_version_org_idx', ['checklist_section_id', 'checklist_version_id', 'organization_id']],
            ['inspection_answers', 'ins_answers_quest_sect_org_idx', ['question_id', 'checklist_section_id', 'organization_id']],
        ] as [$table, $name, $fields]) {
            $index = $this->index($table, $name);
            $this->assertSame($fields, array_map(fn ($row) => $row->COLUMN_NAME, $index));
            $this->assertSame(str_contains($name, '_unique') ? 0 : 1, (int) $index[0]->NON_UNIQUE);
        }

        $this->assertForeignKey('inspection_answers', 'ins_answers_insp_version_org_fk', 'inspections', [
            ['inspection_id', 'id'], ['checklist_version_id', 'checklist_version_id'], ['organization_id', 'organization_id'],
        ], 'CASCADE');
        $this->assertForeignKey('inspection_answers', 'ins_answers_sect_version_org_fk', 'checklist_sections', [
            ['checklist_section_id', 'id'], ['checklist_version_id', 'checklist_version_id'], ['organization_id', 'organization_id'],
        ], 'RESTRICT');
        $this->assertForeignKey('inspection_answers', 'ins_answers_quest_sect_org_fk', 'questions', [
            ['question_id', 'id'], ['checklist_section_id', 'checklist_section_id'], ['organization_id', 'organization_id'],
        ], 'RESTRICT');
        $this->assertForeignKey('evidences', 'evidences_inspection_answer_id_foreign', 'inspection_answers', [
            ['inspection_answer_id', 'id'],
        ], 'RESTRICT');
    }

    private function assertForeignKey(string $table, string $name, string $parent, array $columns, string $delete): void
    {
        $rows = $this->foreignKey($table, $name);
        $this->assertSame(array_column($columns, 0), array_map(fn ($row) => $row->COLUMN_NAME, $rows));
        $this->assertSame(array_column($columns, 1), array_map(fn ($row) => $row->REFERENCED_COLUMN_NAME, $rows));
        foreach ($rows as $row) {
            $this->assertSame($parent, $row->REFERENCED_TABLE_NAME);
            $this->assertSame('CASCADE', $row->UPDATE_RULE);
            $this->assertSame($delete, $row->DELETE_RULE);
        }
    }

    public function test_up_down_up_conserva_datos_backfill_indices_ajenos_y_evidencias(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();
        DB::table('inspection_answers')->insert($this->answer());
        DB::table('inspection_answers')->insert($this->answer(['id' => 'ans2', 'organization_id' => 'o2', 'inspection_id' => 'i2', 'question_id' => 'q2']));
        DB::table('evidences')->insert(['id' => 'e1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'inspection_answer_id' => 'ans1', 'uploaded_by' => 'u1', 'file_name' => 'f.jpg', 'storage_path' => 'p/f.jpg']);
        DB::statement('CREATE INDEX `extra_answer_idx` ON `inspection_answers` (`answer`)');

        $oldAnswers = DB::table('inspection_answers')->orderBy('id')->get()->toArray();
        $evidences = DB::table('evidences')->get()->toArray();

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
            $this->assertCount(1, $this->index('inspection_answers', 'extra_answer_idx'));
        }
    }

    public function test_fk_compuestas_rechazan_cruces_duplicados_y_borrados_invalidos(): void
    {
        $this->seedData();
        DB::table('inspection_answers')->insert($this->newAnswer());

        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer(['id' => 'ansdup'])), 'Duplicate entry');
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'cross1', 'question_id' => 'q2', 'checklist_version_id' => 'v2', 'checklist_section_id' => 's2',
        ])));
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'cross2', 'question_id' => 'q1b', 'checklist_version_id' => 'v1', 'checklist_section_id' => 's1',
        ])));
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'cross3', 'question_id' => 'q1c', 'checklist_version_id' => 'v1', 'checklist_section_id' => 's1b',
        ])));
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'cross4', 'question_id' => 'q1b', 'checklist_version_id' => 'v1b', 'checklist_section_id' => 's1b',
        ])));
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'cross5', 'inspection_id' => 'i2',
        ])));

        $this->rejects(fn () => DB::table('questions')->where('id', 'q1')->delete());
        $this->rejects(fn () => DB::table('checklist_sections')->where('id', 's1')->delete());

        DB::table('inspections')->where('id', 'i1')->delete();
        $this->assertSame(0, DB::table('inspection_answers')->where('inspection_id', 'i1')->count());
    }

    public function test_preflight_rechaza_inconsistencias_sin_ejecutar_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();

        foreach ([
            $this->answer(['id' => 'bad1', 'question_id' => 'q2']),
            $this->answer(['id' => 'bad2', 'inspection_id' => 'i2']),
            $this->answer(['id' => 'bad3', 'question_id' => 'q1b']),
        ] as $row) {
            DB::table('inspection_answers')->insert($row);
            $columns = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'inspection_answers')->count();
            try {
                $migration->up();
                $this->fail('El preflight debía rechazar la respuesta inconsistente.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('huérfanas', $exception->getMessage());
            }
            $this->assertSame($columns, DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'inspection_answers')->count());
            $this->assertSame(0, DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('INDEX_NAME', 'ins_answers_insp_version_org_unique')->count());
            DB::table('inspection_answers')->where('id', $row['id'])->delete();
        }
    }

    public function test_estado_parcial_se_rechaza_sin_continuar_el_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement('ALTER TABLE `inspection_answers` ADD COLUMN `checklist_version_id` CHAR(36) NULL');
        try {
            $migration->up();
            $this->fail('El estado parcial debía rechazarse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('columnas inesperadas', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('INDEX_NAME', 'ins_answers_insp_version_org_unique')->count());
        $this->assertSame(0, DB::table('inspection_answers')->count());
    }

    public function test_actualizar_el_identificador_de_la_inspeccion_se_propaga_en_cascade_sin_romper_la_fk_compuesta(): void
    {
        $this->seedData();
        DB::table('inspection_answers')->insert($this->newAnswer());
        DB::table('evidences')->insert([
            'id' => 'e1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'inspection_answer_id' => 'ans1',
            'uploaded_by' => 'u1', 'file_name' => 'f.jpg', 'storage_path' => 'p/f.jpg',
        ]);

        // ON UPDATE CASCADE de la FK compuesta: la respuesta sigue apuntando a
        // la inspección renombrada y la versión y la organización no cambian.
        DB::statement('UPDATE `inspections` SET `id` = \'i1b\' WHERE `id` = \'i1\'');

        $answer = DB::table('inspection_answers')->where('id', 'ans1')->first();
        $this->assertNotNull($answer);
        $this->assertSame('i1b', $answer->inspection_id);
        $this->assertSame('v1', $answer->checklist_version_id);
        $this->assertSame('s1', $answer->checklist_section_id);
        $this->assertSame('o1', $answer->organization_id);
        $this->assertSame('q1', $answer->question_id);

        // La evidencia heredada también se renombra por su propia FK.
        $this->assertSame('i1b', DB::table('evidences')->where('id', 'e1')->value('inspection_id'));
        $this->assertSame(1, DB::table('inspection_answers')->count());
        $this->assertSame(1, DB::table('evidences')->count());

        // La FK compuesta sigue satisfecha: el par (inspección, versión,
        // organización) continúa existiendo en la tabla padre.
        $this->assertTrue(
            DB::table('inspections')
                ->where('id', 'i1b')->where('checklist_version_id', 'v1')->where('organization_id', 'o1')
                ->exists(),
        );

        // Renombrar a un identificador ya existente se rechaza.
        $this->rejects(fn () => DB::table('inspections')->where('id', 'i1b')->update(['id' => 'i2']), 'Duplicate entry');
        $this->assertSame('i1b', DB::table('inspections')->where('id', 'i1b')->value('id'));
    }

    public function test_borrar_pregunta_o_seccion_con_respuestas_se_rechaza_y_conserva_los_datos(): void
    {
        $this->seedData();
        DB::table('inspection_answers')->insert($this->newAnswer());

        // RESTRICT de la FK compuesta hacia `questions`.
        $this->rejects(fn () => DB::table('questions')->where('id', 'q1')->delete());
        $this->assertTrue(DB::table('questions')->where('id', 'q1')->exists());
        $this->assertSame(1, DB::table('inspection_answers')->count());
        $this->assertSame('ans1', DB::table('inspection_answers')->value('id'));

        // RESTRICT de la FK compuesta hacia `checklist_sections`.
        $this->rejects(fn () => DB::table('checklist_sections')->where('id', 's1')->delete());
        $this->assertTrue(DB::table('checklist_sections')->where('id', 's1')->exists());
        $this->assertSame(1, DB::table('inspection_answers')->count());

        // Una pregunta sin respuestas sí puede eliminarse: el RESTRICT solo
        // actúa cuando existen respuestas que la referencian.
        DB::table('questions')->where('id', 'q1c')->delete();
        $this->assertFalse(DB::table('questions')->where('id', 'q1c')->exists());
        $this->assertSame(1, DB::table('inspection_answers')->count());
    }

    public function test_borrar_inspeccion_sin_evidencias_elimina_la_respuesta_en_cascade(): void
    {
        $this->seedData();
        DB::table('inspection_answers')->insert($this->newAnswer());
        DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'ans2', 'organization_id' => 'o2', 'inspection_id' => 'i2', 'question_id' => 'q2',
            'checklist_version_id' => 'v2', 'checklist_section_id' => 's2',
        ]));

        DB::table('inspections')->where('id', 'i1')->delete();

        $this->assertSame(0, DB::table('inspection_answers')->where('inspection_id', 'i1')->count());
        $this->assertSame(1, DB::table('inspection_answers')->where('inspection_id', 'i2')->count());
        $this->assertSame(1, DB::table('inspection_answers')->count());
    }

    public function test_borrar_inspeccion_con_evidencias_se_rechaza_y_conserva_respuestas_y_evidencias(): void
    {
        $this->seedData();
        DB::table('inspection_answers')->insert($this->newAnswer());
        DB::table('evidences')->insert([
            'id' => 'e1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'inspection_answer_id' => 'ans1',
            'uploaded_by' => 'u1', 'file_name' => 'f.jpg', 'storage_path' => 'p/f.jpg',
        ]);

        // La evidencia referencia la inspección (RESTRICT) y la respuesta
        // (RESTRICT): el borrado en cascada se rechaza.
        $this->rejects(fn () => DB::table('inspections')->where('id', 'i1')->delete());
        $this->assertTrue(DB::table('inspections')->where('id', 'i1')->exists());
        $this->assertSame(1, DB::table('inspection_answers')->where('id', 'ans1')->count());
        $this->assertSame(1, DB::table('evidences')->where('id', 'e1')->count());

        // Retirada la evidencia, el borrado en cascada sí procede.
        DB::table('evidences')->where('id', 'e1')->delete();
        DB::table('inspections')->where('id', 'i1')->delete();
        $this->assertSame(0, DB::table('inspection_answers')->where('id', 'ans1')->count());
        $this->assertFalse(DB::table('inspections')->where('id', 'i1')->exists());
    }

    public function test_actualizar_la_version_o_la_seccion_padre_no_deja_filas_inconsistentes(): void
    {
        $this->seedData();
        DB::table('inspection_answers')->insert($this->newAnswer());

        // Se aparta la colisión del índice único (versión, orden) para que el
        // rechazo provenga de la FK compuesta y no de un índice hermano.
        DB::table('checklist_sections')->where('id', 's1b')->update(['display_order' => 2]);

        // Mover la sección a otra versión dejaría la respuesta con una versión
        // que ya no coincide con la de su inspección.
        $this->rejects(fn () => DB::table('checklist_sections')->where('id', 's1')->update(['checklist_version_id' => 'v1b']));
        $this->assertSame('v1', DB::table('checklist_sections')->where('id', 's1')->value('checklist_version_id'));
        $this->assertSame('v1', DB::table('inspection_answers')->where('id', 'ans1')->value('checklist_version_id'));

        // Renombrar la sección affecta a la columna compartida por dos FK
        // compuestas (sección y pregunta): el motor rechaza el cambio.
        $this->rejects(fn () => DB::table('checklist_sections')->where('id', 's1')->update(['id' => 's1x']));
        $this->assertSame('s1', DB::table('checklist_sections')->where('id', 's1')->value('id'));
        $this->assertSame('s1', DB::table('inspection_answers')->where('id', 'ans1')->value('checklist_section_id'));

        // Renombrar la versión arrastra secciones, inspecciones y respuestas:
        // la FK compuesta impide dejar la respuesta apuntando a una versión
        // que su inspección no tiene.
        $this->rejects(fn () => DB::table('checklist_versions')->where('id', 'v1')->update(['id' => 'v1x']));
        $this->assertSame('v1', DB::table('checklist_versions')->where('id', 'v1')->value('id'));
        $this->assertSame('v1', DB::table('inspection_answers')->where('id', 'ans1')->value('checklist_version_id'));
        $this->assertSame(1, DB::table('inspection_answers')->count());
        $this->assertSame('q1', DB::table('inspection_answers')->where('id', 'ans1')->value('question_id'));
    }

    public function test_las_fk_compuestas_rechazan_organizacion_version_y_seccion_inconsistentes(): void
    {
        $this->seedData();

        // Control: la combinación coherente se acepta.
        DB::table('inspection_answers')->insert($this->newAnswer());
        $this->assertSame(1, DB::table('inspection_answers')->count());

        // Preguntas adicionales para que cada intento incoherente use una
        // pareja (inspección, pregunta) libre y el rechazo provenga de la FK
        // compuesta y no del índice único histórico.
        $questions = [];
        foreach (range(3, 12) as $order) {
            $id = 'q1x'.$order;
            DB::table('questions')->insert([
                'id' => $id, 'organization_id' => 'o1', 'checklist_section_id' => 's1',
                'question_text' => 'Question '.$id, 'answer_type' => 'text', 'status' => 1, 'display_order' => $order,
            ]);
            $questions[] = $id;
        }

        // Organización distinta en la respuesta.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'x1', 'organization_id' => 'o2', 'question_id' => $questions[0],
        ])));

        // Versión distinta de la de la inspección (misma organización).
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'x2', 'checklist_version_id' => 'v1b', 'question_id' => $questions[1],
        ])));

        // Sección que no corresponde a la pregunta.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'x3', 'checklist_section_id' => 's1b', 'question_id' => $questions[2],
        ])));

        // Pregunta de otra organización.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'x4', 'question_id' => 'q2',
        ])));

        // Pregunta de otra versión (otra sección).
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'x5', 'question_id' => 'q1b',
        ])));

        // Inspección de otra organización con la misma versión.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'x6', 'inspection_id' => 'i2',
        ])));

        // Inspección de otra organización con su propia versión y sección.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'x7', 'inspection_id' => 'i2', 'question_id' => 'q2',
            'checklist_version_id' => 'v2', 'checklist_section_id' => 's2',
        ])));

        // Inspección de otra organización con la versión y la pregunta de la
        // organización original.
        $this->rejects(fn () => DB::table('inspection_answers')->insert($this->newAnswer([
            'id' => 'x8', 'inspection_id' => 'i2', 'question_id' => $questions[3],
        ])));

        $this->assertSame(1, DB::table('inspection_answers')->count());
        $this->assertSame('ans1', DB::table('inspection_answers')->value('id'));
    }
}
