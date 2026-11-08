<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NormsMigrationReversibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_aborta_si_hay_normas_duplicadas_entre_organizaciones(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Verificación equivalente en MariaDB: NormsMigrationReversibilityMariaDbTest');
        }

        $this->seed();

        // Confirmar que existe el unique por organización antes del intento.
        $this->assertTrue($this->hasIndex('norms_org_code_version_unique'));

        $thrown = false;
        try {
            Artisan::call('migrate:rollback', ['--step' => 1]);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString('No se puede revertir la migración', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Se esperaba que el rollback abortara con excepción.');
        $this->assertTrue($this->hasIndex('norms_org_code_version_unique'));
        $this->assertFalse($this->hasIndex('norms_code_version_unique'));
    }

    public function test_rollback_revierte_y_remigra_si_no_hay_duplicados(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Verificación equivalente en MariaDB: NormsMigrationReversibilityMariaDbTest');
        }

        $this->seed();

        // Dejar una sola organización con normas (eliminar duplicados entre orgs).
        $clientIds = DB::table('organizations')->where('type', 'client')->pluck('id')->all();
        $keep = $clientIds[0];
        DB::table('norms')->where('organization_id', '!=', $keep)->delete();
        DB::table('checklists')->where('organization_id', '!=', $keep)->delete();
        // Eliminar plantas/areas/etc. de otras orgs no es necesario para la migración de norms.

        $exitCode = Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertSame(0, $exitCode);

        $this->assertTrue($this->hasIndex('norms_code_version_unique'));
        $this->assertFalse($this->hasIndex('norms_org_code_version_unique'));

        // Re-migrar.
        $exitCode = Artisan::call('migrate');
        $this->assertSame(0, $exitCode);

        $this->assertTrue($this->hasIndex('norms_org_code_version_unique'));
    }

    private function hasIndex(string $name): bool
    {
        $rows = DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?",
            [$name]
        );

        return count($rows) > 0;
    }
}
