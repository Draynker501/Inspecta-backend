<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class EhsDec17PartialDdlMariaDbTest extends TestCase
{
    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_aplicacion_parcial_dec17_se_bloquea_y_se_recupera_supervisadamente(): void
    {
        $db = DB::connection();
        if (! in_array($db->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Solo MariaDB efímera.');
        }
        $this->assertStringEndsWith('_test', $db->getDatabaseName());
        $this->assertSame('127.0.0.1', $db->getConfig('host'));
        Artisan::call('migrate:fresh', ['--force' => true]);
        $name = '2026_11_17_000001_enforce_ehs_pivot_tenant_foreign_keys';
        $migration = require database_path('migrations/'.$name.'.php');
        $migration->down();
        $db->table('migrations')->where('migration', $name)->delete();

        $db->statement('ALTER TABLE `questions` ADD UNIQUE KEY `ehs_questions_id_org_unique` (`id`, `organization_id`)');

        $this->assertTrue($this->indexExists('questions', 'ehs_questions_id_org_unique'));
        $this->assertFalse($this->indexExists('norm_requirements', 'ehs_requirements_id_org_unique'));
        $this->assertFalse($this->fkExists('ehs_qnr_question_org_fk'));
        $this->assertFalse($this->fkExists('ehs_qnr_requirement_org_fk'));

        try {
            $migration->up();
            $this->fail('No debe reintentarse automáticamente con un esquema parcial.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Estado parcial', $exception->getMessage());
        }

        $db->statement('ALTER TABLE `questions` DROP INDEX `ehs_questions_id_org_unique`');
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame(1, $db->table('migrations')->where('migration', $name)->count());
        $this->assertTrue($this->indexExists('questions', 'ehs_questions_id_org_unique'));
        $this->assertTrue($this->indexExists('norm_requirements', 'ehs_requirements_id_org_unique'));
        $this->assertTrue($this->fkExists('ehs_qnr_question_org_fk'));
        $this->assertTrue($this->fkExists('ehs_qnr_requirement_org_fk'));
    }

    private function indexExists(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)->where('INDEX_NAME', $name)->exists();
    }

    private function fkExists(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'question_norm_requirement')
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')->exists();
    }
}
