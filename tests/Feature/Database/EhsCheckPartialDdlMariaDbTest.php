<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class EhsCheckPartialDdlMariaDbTest extends TestCase
{
    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_fallo_controlado_segundo_alter_exige_recuperacion_supervisada(): void
    {
        $db = DB::connection();
        if (! in_array($db->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Solo MariaDB efímera.');
        }
        $this->assertStringEndsWith('_test', $db->getDatabaseName());
        $this->assertSame('127.0.0.1', $db->getConfig('host'));
        Artisan::call('migrate:fresh', ['--force' => true]);
        $name = '2026_11_16_000001_add_positive_display_order_checks';
        $migration = require database_path("migrations/$name.php");
        $migration->down();
        $db->table('migrations')->where('migration', $name)->delete();

        try {
            $db->statement('ALTER TABLE `checklist_sections` ADD CONSTRAINT `sections_display_order_positive` CHECK (`display_order` >= 1)');
            // Fallo inyectado exclusivamente en la segunda sentencia: simula
            // un error de DDL después del COMMIT implícito del primer ALTER.
            try {
                $db->statement('ALTER TABLE `questions` ADD CONSTRAINT `questions_display_order_positive` CHECK (`columna_inexistente` >= 1)');
                $this->fail('La segunda sentencia debe fallar.');
            } catch (QueryException) {
                $this->assertSame(0, $db->table('migrations')->where('migration', $name)->count());
            }
            $this->assertTrue($this->checkExists('checklist_sections', 'sections_display_order_positive'));
            $this->assertFalse($this->checkExists('questions', 'questions_display_order_positive'));
            try {
                $migration->up();
                $this->fail('No debe reintentarse automáticamente con esquema parcial.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Estado de CHECK EHS inesperado', $exception->getMessage());
            }

            // Recuperación supervisada: comprobar estado y datos, deshacer
            // únicamente la primera operación y repetir el migrate normal.
            $this->assertSame(0, $db->table('checklist_sections')->count());
            $this->assertSame(0, $db->table('questions')->count());
            $db->statement('ALTER TABLE `checklist_sections` DROP CONSTRAINT `sections_display_order_positive`');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $this->assertSame(1, $db->table('migrations')->where('migration', $name)->count());
            $this->assertTrue($this->checkExists('checklist_sections', 'sections_display_order_positive'));
            $this->assertTrue($this->checkExists('questions', 'questions_display_order_positive'));
        } finally {
            // La prueba solo opera sobre la base efímera _test. Evitar dejar
            // el esquema parcial para la prueba siguiente incluso ante error.
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    private function checkExists(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'CHECK')->exists();
    }
}
