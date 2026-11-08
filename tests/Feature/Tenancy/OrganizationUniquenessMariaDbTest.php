<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationUniquenessMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true);
    }

    public function test_unicos_globales_ya_no_existen(): void
    {
        if (! $this->isMariaDb()) {
            $this->markTestSkipped('Verificación equivalente en SQLite: OrganizationUniquenessTest');
        }

        $indexesRoles = DB::select(
            "SELECT DISTINCT INDEX_NAME AS name
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles'"
        );
        $indexNamesRoles = array_column($indexesRoles, 'name');

        $indexesPlants = DB::select(
            "SELECT DISTINCT INDEX_NAME AS name
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plants'"
        );
        $indexNamesPlants = array_column($indexesPlants, 'name');

        $this->assertNotContains('roles_name_unique', $indexNamesRoles);
        $this->assertNotContains('plants_code_unique', $indexNamesPlants);
        $this->assertContains('roles_org_name_unique', $indexNamesRoles);
        $this->assertContains('plants_org_code_unique', $indexNamesPlants);
    }
}
