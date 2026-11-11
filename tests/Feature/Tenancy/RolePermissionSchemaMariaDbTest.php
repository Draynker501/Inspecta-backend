<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolePermissionSchemaMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true);
    }

    private function skipIfNotMariaDb(): void
    {
        if (! $this->isMariaDb()) {
            $this->markTestSkipped('Verificación equivalente en SQLite: RolePermissionSchemaTest');
        }
    }

    public function test_fk_compuesta_role_org_existe(): void
    {
        $this->skipIfNotMariaDb();

        $rows = DB::select(
            'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
             ORDER BY ORDINAL_POSITION',
            ['role_permissions', 'role_permissions_role_id_organization_id_foreign']
        );

        $this->assertCount(2, $rows);
        $this->assertSame(['role_id', 'organization_id'], array_column($rows, 'COLUMN_NAME'));
        $this->assertSame(['roles'], array_values(array_unique(array_column($rows, 'REFERENCED_TABLE_NAME'))));
        $this->assertSame(['id', 'organization_id'], array_column($rows, 'REFERENCED_COLUMN_NAME'));
    }

    public function test_unico_role_org_permission_existe(): void
    {
        $this->skipIfNotMariaDb();

        $rows = DB::select(
            'SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
               AND NON_UNIQUE = 0
             ORDER BY SEQ_IN_INDEX',
            ['role_permissions', 'role_permissions_role_org_perm_unique']
        );

        $this->assertNotEmpty($rows);
        $this->assertSame(['role_id', 'organization_id', 'permission'], array_column($rows, 'COLUMN_NAME'));
    }

    public function test_fk_compuesta_reglas_on_delete_y_on_update(): void
    {
        $this->skipIfNotMariaDb();

        $rows = DB::select(
            'SELECT DELETE_RULE, UPDATE_RULE
             FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = ?',
            ['role_permissions_role_id_organization_id_foreign']
        );

        $this->assertNotEmpty($rows);
        $this->assertSame('RESTRICT', $rows[0]->DELETE_RULE);
        $this->assertSame('CASCADE', $rows[0]->UPDATE_RULE);
    }
}
