<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NormsMigrationReversibilityMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true);
    }

    public function test_rollback_aborta_si_hay_normas_duplicadas_entre_organizaciones(): void
    {
        if (! $this->isMariaDb()) {
            $this->markTestSkipped('Verificación equivalente en SQLite: NormsMigrationReversibilityTest');
        }

        $this->seed();

        $this->assertTrue($this->hasIndex('norms_org_code_version_unique'));

        $thrown = false;
        try {
            Artisan::call('migrate:rollback', ['--step' => 1]);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString('No se puede revertir la migración', $e->getMessage());
        } finally {
            Artisan::call('migrate');
        }

        $this->assertTrue($thrown, 'Se esperaba que el rollback abortara con excepción.');
        $this->assertTrue($this->hasIndex('norms_org_code_version_unique'));
        $this->assertFalse($this->hasIndex('norms_code_version_unique'));
    }

    public function test_rollback_revierte_y_remigra_si_no_hay_duplicados(): void
    {
        if (! $this->isMariaDb()) {
            $this->markTestSkipped('Verificación equivalente en SQLite: NormsMigrationReversibilityTest');
        }

        $this->seed();

        $clientIds = DB::table('organizations')->where('type', 'client')->pluck('id')->all();
        $keep = $clientIds[0];
        DB::table('norms')->where('organization_id', '!=', $keep)->delete();
        DB::table('checklists')->where('organization_id', '!=', $keep)->delete();

        try {
            $exitCode = Artisan::call('migrate:rollback', ['--step' => 1]);
            $this->assertSame(0, $exitCode);

            $this->assertTrue($this->hasIndex('norms_code_version_unique'));
            $this->assertFalse($this->hasIndex('norms_org_code_version_unique'));

            $exitCode = Artisan::call('migrate');
            $this->assertSame(0, $exitCode);

            $this->assertTrue($this->hasIndex('norms_org_code_version_unique'));
        } finally {
            Artisan::call('migrate');
        }
    }

    private function hasIndex(string $name): bool
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS c
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            ['norms', $name]
        );

        return (int) ($rows[0]->c ?? 0) > 0;
    }
}
