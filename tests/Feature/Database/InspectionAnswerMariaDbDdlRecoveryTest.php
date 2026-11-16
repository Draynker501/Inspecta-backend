<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Recuperación ante DDL parcial de la migración INS-02 en MariaDB 11.8.
 *
 * Fronteras DDL de la migración (cada `ALTER TABLE` de MariaDB implica un
 * COMMIT implícito, de modo que cada sentencia es una frontera de
 * recuperación):
 *
 * 1. Lectura de `information_schema` (estado previo y preflight de datos).
 * 2. `ADD COLUMN` de `checklist_version_id` y `checklist_section_id`.
 * 3. Backfill de ambas columnas mediante `UPDATE ... JOIN`.
 * 4. `MODIFY ... NOT NULL` de ambas columnas.
 * 5. `ADD UNIQUE KEY` de los tres índices padre.
 * 6. `ADD INDEX` de los tres índices hijo.
 * 7. Conmutación de FK en un único `ALTER TABLE`: `DROP FOREIGN KEY` de las
 *    dos FK simples y `ADD CONSTRAINT` de las tres FK compuestas. MariaDB
 *    11.8 aplica DDL atómico, de modo que no existe una ventana sin FK entre
 *    el `DROP` y el `ADD`.
 *
 * Simulación del fallo parcial: se retiene un bloqueo de metadatos
 * (`LOCK TABLES inspections READ`) desde una SEGUNDA conexión mientras la
 * migración se ejecuta. Es una causa representativa de un DDL interrumpido
 * a mitad (otro proceso mantiene una transacción abierta sobre la tabla
 * padre) y es controlable: la migración falla con un error de espera de
 * bloqueo (1205) DESPUÉS de haber añadido las columnas y ANTES de crear los
 * índices, dejando el esquema en un estado parcial real.
 *
 * Fronteras NO ensayadas (y por qué):
 *
 * - Fallo en la frontera 1 (lectura de `information_schema`): requiere que
 *   `information_schema` deje de ser legible, algo que no puede provocarse
 *   sin alterar la instancia completa de MariaDB.
 * - Fallo en la frontera 3 (backfill) y en la 4 (`MODIFY NOT NULL`): requieren
 *   datos que violen la coherencia_version-organización o una restricción
 *   `NOT NULL` que el propio motor impone; el preflight los rechaza antes
 *   del DDL, de modo que no son alcanzables por un fallo de motor.
 * - Fallo en la frontera 5 con el índice padre ya creado: el preflight
 *   detecta antes cualquier índice padre preexistente, por lo que la
 *   comprobación de estado siempre falla primero; no es alcanzable como
 *   estado parcial intermedio.
 * - Fallo dentro de la frontera 7: al ser un único `ALTER TABLE` atómico no
 *   existe estado parcial intermedio. La atomicidad se comprueba directamente
 *   en `test_el_alter_agrupado_de_fk_es_atomico_ante_un_add_invalido`, pero no
 *   se induce un fallo dentro de la ejecución real de la migración.
 * - Recuperación automática: la migración no repara por sí sola; la
 *   recuperación ensayada es supervisada (deshacer el paso fallido y volver
 *   a ejecutar el migrador).
 *
 * La cobertura es representativa, no exhaustiva.
 */
class InspectionAnswerMariaDbDdlRecoveryTest extends TestCase
{
    private const MIGRATION = '2026_11_19_000001_enforce_inspection_answer_tenant_version_foreign_keys';

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
        DB::purge('ins02_locker');
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /**
     * Deja la migración pendiente y el esquema en su estado previo.
     */
    private function revertAndMarkPending(): void
    {
        $this->migration()->down();
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    private function seedData(): void
    {
        DB::table('organizations')->insert(['id' => 'o1', 'name' => 'Org 1', 'code' => 'O1', 'type' => 'client', 'status' => 1]);
        DB::table('plants')->insert(['id' => 'p1', 'organization_id' => 'o1', 'name' => 'Plant 1', 'code' => 'P1', 'status' => 1]);
        DB::table('areas')->insert(['id' => 'a1', 'plant_id' => 'p1', 'organization_id' => 'o1', 'name' => 'Area 1', 'code' => 'A1', 'status' => 1]);
        DB::table('users')->insert(['id' => 'u1', 'organization_id' => 'o1', 'name' => 'User 1', 'email' => 'u1@example.test', 'password' => 'test', 'status' => 1]);
        DB::table('checklists')->insert(['id' => 'c1', 'organization_id' => 'o1', 'name' => 'Checklist 1', 'status' => 1]);
        DB::table('checklist_versions')->insert(['id' => 'v1', 'organization_id' => 'o1', 'checklist_id' => 'c1', 'version' => '1', 'created_by' => 'u1', 'status' => 'published', 'published_at' => now()]);
        DB::table('checklist_sections')->insert(['id' => 's1', 'organization_id' => 'o1', 'checklist_version_id' => 'v1', 'name' => 'Section 1', 'display_order' => 1]);
        DB::table('questions')->insert(['id' => 'q1', 'organization_id' => 'o1', 'checklist_section_id' => 's1', 'question_text' => 'Question 1', 'answer_type' => 'text', 'status' => 1, 'display_order' => 1]);
        DB::table('inspections')->insert(['id' => 'i1', 'organization_id' => 'o1', 'user_id' => 'u1', 'checklist_version_id' => 'v1', 'plant_id' => 'p1', 'area_id' => 'a1', 'status' => 'draft']);
    }

    /**
     * @return array<int, object>
     */
    private function foreignKeys(string $table): array
    {
        return DB::select(
            'SELECT DISTINCT k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, r.UPDATE_RULE, r.DELETE_RULE '
            .'FROM information_schema.KEY_COLUMN_USAGE k '
            .'JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
            .'AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
            .'WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            [DB::getDatabaseName(), $table],
        );
    }

    /**
     * @return list<string>
     */
    private function foreignKeyNames(string $table): array
    {
        $names = array_map(fn ($row): string => (string) $row->CONSTRAINT_NAME, $this->foreignKeys($table));
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'inspection_answers')
            ->orderBy('ORDINAL_POSITION')
            ->pluck('COLUMN_NAME')
            ->all();
    }

    private function indexExists(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }

    public function test_estado_parcial_con_columna_sin_fk_aborta_sin_aplicar_el_resto(): void
    {
        $this->revertAndMarkPending();
        $this->seedData();
        DB::table('inspection_answers')->insert(['id' => 'ans1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'question_id' => 'q1', 'answer' => 'texto']);

        // Frontera 2 aplicada y frontieras 5 a 8 pendientes.
        DB::statement('ALTER TABLE `inspection_answers` ADD COLUMN `checklist_version_id` CHAR(36) NULL');

        try {
            $this->migration()->up();
            $this->fail('El estado parcial debía rechazarse.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('columnas inesperadas', $exception->getMessage());
        }

        $this->assertFalse($this->indexExists('inspections', 'ins_answers_insp_version_org_unique'));
        $this->assertFalse($this->indexExists('inspection_answers', 'ins_answers_insp_version_org_idx'));
        $this->assertSame(
            ['inspection_answers_inspection_id_foreign', 'inspection_answers_organization_id_foreign', 'inspection_answers_question_id_foreign'],
            $this->foreignKeyNames('inspection_answers'),
        );
        $this->assertSame(1, DB::table('inspection_answers')->count());
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());

        // El reintento sigue siendo rechazado: no es un reintento inseguro.
        try {
            $this->migration()->up();
            $this->fail('El reintento debía rechazarse.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('columnas inesperadas', $exception->getMessage());
        }
        $this->assertFalse($this->indexExists('inspections', 'ins_answers_insp_version_org_unique'));
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    public function test_estado_parcial_con_indice_padre_sin_indice_hijo_aborta_sin_aplicar_el_resto(): void
    {
        $this->revertAndMarkPending();
        $this->seedData();
        DB::table('inspection_answers')->insert(['id' => 'ans1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'question_id' => 'q1', 'answer' => 'texto']);

        // Frontera 5 aplicada solo para `inspections`: falta el índice hijo y
        // las tres FK compuestas.
        DB::statement('ALTER TABLE `inspections` ADD UNIQUE KEY `ins_answers_insp_version_org_unique` (`id`, `checklist_version_id`, `organization_id`)');

        try {
            $this->migration()->up();
            $this->fail('El estado parcial debía rechazarse.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('estado inesperado del índice', $exception->getMessage());
        }

        $this->assertFalse($this->indexExists('inspection_answers', 'ins_answers_insp_version_org_idx'));
        $this->assertFalse($this->indexExists('checklist_sections', 'ins_answers_sect_version_org_unique'));
        $this->assertFalse($this->indexExists('questions', 'ins_answers_quest_sect_org_unique'));
        $this->assertSame(
            ['inspection_answers_inspection_id_foreign', 'inspection_answers_organization_id_foreign', 'inspection_answers_question_id_foreign'],
            $this->foreignKeyNames('inspection_answers'),
        );
        $this->assertSame(1, DB::table('inspection_answers')->count());
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    public function test_estado_parcial_avanzado_con_columnas_completadas_sin_fk_aborta(): void
    {
        $this->revertAndMarkPending();
        $this->seedData();
        DB::table('inspection_answers')->insert(['id' => 'ans1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'question_id' => 'q1', 'answer' => 'texto']);

        // Fronteras 2, 3 y 4 aplicadas: columnas con valores correctos y NOT
        // NULL, pero sin índices ni FK compuestas.
        DB::statement('ALTER TABLE `inspection_answers` ADD COLUMN `checklist_version_id` CHAR(36) NULL, ADD COLUMN `checklist_section_id` CHAR(36) NULL');
        DB::statement('UPDATE `inspection_answers` SET `checklist_version_id` = \'v1\', `checklist_section_id` = \'s1\'');
        DB::statement('ALTER TABLE `inspection_answers` MODIFY `checklist_version_id` CHAR(36) NOT NULL, MODIFY `checklist_section_id` CHAR(36) NOT NULL');

        try {
            $this->migration()->up();
            $this->fail('El estado parcial debía rechazarse.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('columnas inesperadas', $exception->getMessage());
        }

        $this->assertFalse($this->indexExists('inspections', 'ins_answers_insp_version_org_unique'));
        $this->assertSame(
            ['inspection_answers_inspection_id_foreign', 'inspection_answers_organization_id_foreign', 'inspection_answers_question_id_foreign'],
            $this->foreignKeyNames('inspection_answers'),
        );
        $this->assertSame('v1', DB::table('inspection_answers')->value('checklist_version_id'));
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    public function test_fallo_medio_inducido_no_registra_la_migracion_y_exige_recuperacion_supervisada(): void
    {
        $this->revertAndMarkPending();
        $this->seedData();
        DB::table('inspection_answers')->insert(['id' => 'ans1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'question_id' => 'q1', 'answer' => 'texto']);

        // Segunda conexión que retiene el metadato de `inspections` mientras la
        // migración intenta alterar esa tabla.
        config(['database.connections.ins02_locker' => array_merge(config('database.connections.mariadb'), ['name' => 'ins02_locker'])]);
        DB::purge('ins02_locker');
        $locker = DB::connection('ins02_locker');
        $locker->statement('LOCK TABLES `inspections` READ');
        DB::statement('SET SESSION lock_wait_timeout = 3');

        try {
            try {
                Artisan::call('migrate', ['--force' => true]);
                $this->fail('La migración debía fallar por el bloqueo retenido.');
            } catch (QueryException $exception) {
                $this->assertSame('HY000', $exception->errorInfo[0]);
                $this->assertSame(1205, (int) $exception->errorInfo[1]);
            }
        } finally {
            $locker->statement('UNLOCK TABLES');
            DB::statement('SET SESSION lock_wait_timeout = DEFAULT');
        }

        // La migración no queda registrada: el esquema está a medias.
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        $this->assertContains('checklist_version_id', $this->columns());
        $this->assertFalse($this->indexExists('inspections', 'ins_answers_insp_version_org_unique'));
        $this->assertFalse($this->indexExists('inspection_answers', 'ins_answers_insp_version_org_idx'));
        $this->assertSame(
            ['inspection_answers_inspection_id_foreign', 'inspection_answers_organization_id_foreign', 'inspection_answers_question_id_foreign'],
            $this->foreignKeyNames('inspection_answers'),
        );
        $this->assertSame(1, DB::table('inspection_answers')->count());

        // Reintento: se aborta por estado parcial y no se aplica el resto.
        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->fail('El reintento debía rechazarse por el estado parcial.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('columnas inesperadas', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        $this->assertFalse($this->indexExists('inspections', 'ins_answers_insp_version_org_unique'));

        // Recuperación supervisada: deshacer el paso fallido y reintentar.
        DB::statement('ALTER TABLE `inspection_answers` DROP COLUMN `checklist_version_id`, DROP COLUMN `checklist_section_id`');
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        $this->assertTrue($this->indexExists('inspections', 'ins_answers_insp_version_org_unique'));
        $this->assertTrue($this->indexExists('inspection_answers', 'ins_answers_insp_version_org_idx'));
        $this->assertSame(
            ['ins_answers_insp_version_org_fk', 'ins_answers_quest_sect_org_fk', 'ins_answers_sect_version_org_fk', 'inspection_answers_organization_id_foreign'],
            $this->foreignKeyNames('inspection_answers'),
        );
        $this->assertSame(1, DB::table('inspection_answers')->count());
        $this->assertSame('v1', DB::table('inspection_answers')->value('checklist_version_id'));
        $this->assertSame('s1', DB::table('inspection_answers')->value('checklist_section_id'));
    }

    public function test_down_desde_el_estado_esperado_restaura_el_esquema_previo_y_conserva_los_datos(): void
    {
        $this->seedData();
        DB::table('inspection_answers')->insert(['id' => 'ans1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'question_id' => 'q1', 'answer' => 'texto', 'observation' => 'obs', 'checklist_version_id' => 'v1', 'checklist_section_id' => 's1']);
        DB::table('evidences')->insert(['id' => 'e1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'inspection_answer_id' => 'ans1', 'uploaded_by' => 'u1', 'file_name' => 'f.jpg', 'storage_path' => 'p/f.jpg']);
        DB::statement('CREATE INDEX `extra_answer_idx` ON `inspection_answers` (`answer`)');

        $this->migration()->down();

        // Sin columnas nuevas y con las FK simples históricas.
        $this->assertSame(
            ['id', 'organization_id', 'inspection_id', 'question_id', 'answer', 'observation', 'created_at', 'updated_at'],
            $this->columns(),
        );
        $this->assertSame(
            ['inspection_answers_inspection_id_foreign', 'inspection_answers_organization_id_foreign', 'inspection_answers_question_id_foreign'],
            $this->foreignKeyNames('inspection_answers'),
        );
        $inspection = collect($this->foreignKeys('inspection_answers'))->firstWhere('CONSTRAINT_NAME', 'inspection_answers_inspection_id_foreign');
        $this->assertSame('CASCADE', $inspection->DELETE_RULE);
        $this->assertSame('CASCADE', $inspection->UPDATE_RULE);
        $question = collect($this->foreignKeys('inspection_answers'))->firstWhere('CONSTRAINT_NAME', 'inspection_answers_question_id_foreign');
        $this->assertSame('RESTRICT', $question->DELETE_RULE);
        $this->assertSame('CASCADE', $question->UPDATE_RULE);

        // Sin índices propios de INS-02 y con el índice ajeno conservado.
        $this->assertFalse($this->indexExists('inspections', 'ins_answers_insp_version_org_unique'));
        $this->assertFalse($this->indexExists('checklist_sections', 'ins_answers_sect_version_org_unique'));
        $this->assertFalse($this->indexExists('questions', 'ins_answers_quest_sect_org_unique'));
        $this->assertFalse($this->indexExists('inspection_answers', 'ins_answers_insp_version_org_idx'));
        $this->assertFalse($this->indexExists('inspection_answers', 'ins_answers_sect_version_org_idx'));
        $this->assertFalse($this->indexExists('inspection_answers', 'ins_answers_quest_sect_org_idx'));
        $this->assertTrue($this->indexExists('inspection_answers', 'extra_answer_idx'));

        // Datos y evidencias conservados.
        $this->assertSame(1, DB::table('inspection_answers')->count());
        $this->assertSame('texto', DB::table('inspection_answers')->value('answer'));
        $this->assertSame('obs', DB::table('inspection_answers')->value('observation'));
        $this->assertSame(1, DB::table('evidences')->count());
        $this->assertSame('ans1', DB::table('evidences')->value('inspection_answer_id'));
        $this->assertContains('evidences_inspection_answer_id_foreign', $this->foreignKeyNames('evidences'));
    }

    public function test_up_down_up_completo_con_datos_conserva_respuestas_y_evidencias(): void
    {
        $migration = $this->migration();

        // La migración no es idempotente: parte del esquema previo.
        $migration->down();
        $this->seedData();
        DB::table('inspection_answers')->insert(['id' => 'ans1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'question_id' => 'q1', 'answer' => 'texto', 'observation' => 'obs']);
        DB::table('evidences')->insert(['id' => 'e1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'inspection_answer_id' => 'ans1', 'uploaded_by' => 'u1', 'file_name' => 'f.jpg', 'storage_path' => 'p/f.jpg']);
        DB::statement('CREATE INDEX `extra_answer_idx` ON `inspection_answers` (`answer`)');

        foreach (['up', 'down', 'up'] as $direction) {
            $migration->{$direction}();

            $this->assertSame(1, DB::table('inspection_answers')->count());
            $this->assertSame('texto', DB::table('inspection_answers')->value('answer'));
            $this->assertSame('obs', DB::table('inspection_answers')->value('observation'));
            $this->assertSame(1, DB::table('evidences')->count());
            $this->assertSame('ans1', DB::table('evidences')->value('inspection_answer_id'));
            $this->assertTrue($this->indexExists('inspection_answers', 'extra_answer_idx'));

            if ($direction === 'down') {
                $this->assertNotContains('checklist_version_id', $this->columns());
                $this->assertSame(
                    ['inspection_answers_inspection_id_foreign', 'inspection_answers_organization_id_foreign', 'inspection_answers_question_id_foreign'],
                    $this->foreignKeyNames('inspection_answers'),
                );
            } else {
                $this->assertSame('v1', DB::table('inspection_answers')->value('checklist_version_id'));
                $this->assertSame('s1', DB::table('inspection_answers')->value('checklist_section_id'));
                $this->assertSame(
                    ['ins_answers_insp_version_org_fk', 'ins_answers_quest_sect_org_fk', 'ins_answers_sect_version_org_fk', 'inspection_answers_organization_id_foreign'],
                    $this->foreignKeyNames('inspection_answers'),
                );
            }
        }
    }

    /**
     * La conmutación de FK de la migración se ejecuta en un único `ALTER
     * TABLE`. MariaDB 11.8 revierte la sentencia completa si una cláusula
     * falla: nunca queda la tabla sin FK entre el `DROP` y el `ADD`.
     */
    public function test_el_alter_agrupado_de_fk_es_atomico_ante_un_add_invalido(): void
    {
        $this->seedData();
        DB::table('inspection_answers')->insert(['id' => 'ans1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'question_id' => 'q1', 'answer' => 'texto', 'checklist_version_id' => 'v1', 'checklist_section_id' => 's1']);

        $before = $this->foreignKeyNames('inspection_answers');
        $this->assertContains('ins_answers_insp_version_org_fk', $before);

        try {
            // Mismo patrón que la migración (DROP + ADD en un único ALTER),
            // con una referencia inválida en el ADD.
            DB::statement(
                'ALTER TABLE `inspection_answers` '
                .'DROP FOREIGN KEY `ins_answers_insp_version_org_fk`, '
                .'DROP FOREIGN KEY `ins_answers_quest_sect_org_fk`, '
                .'ADD CONSTRAINT `ins_answers_probe_fk` FOREIGN KEY (`inspection_id`) REFERENCES `tabla_inexistente` (`id`)'
            );
            $this->fail('La cláusula inválida debía rechazarse.');
        } catch (QueryException $exception) {
            $this->assertSame('HY000', $exception->errorInfo[0]);
            $this->assertSame(1005, (int) $exception->errorInfo[1]);
        }

        // La sentencia completa se revirtió: las FK siguen presentes.
        $this->assertSame($before, $this->foreignKeyNames('inspection_answers'));
        $this->assertSame(1, DB::table('inspection_answers')->count());
    }
}
