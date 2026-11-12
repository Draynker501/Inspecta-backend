<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolePermissionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabla_role_permissions_existe_con_columnas_esperadas(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Verificación equivalente en MariaDB: RolePermissionSchemaMariaDbTest');
        }

        $columns = DB::select('PRAGMA table_info(role_permissions)');
        $columnNames = array_column($columns, 'name');

        foreach (['id', 'role_id', 'organization_id', 'permission', 'created_at', 'updated_at'] as $col) {
            $this->assertContains($col, $columnNames, "Falta la columna {$col} en role_permissions.");
        }
    }

    public function test_unico_role_org_permission_existe(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Verificación equivalente en MariaDB: RolePermissionSchemaMariaDbTest');
        }

        $indexes = DB::select('PRAGMA index_list(role_permissions)');
        $indexNames = array_column($indexes, 'name');

        $this->assertContains('role_permissions_role_org_perm_unique', $indexNames);
    }

    public function test_roles_tiene_unico_id_org_para_fk_compuesta(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Verificación equivalente en MariaDB: RolePermissionSchemaMariaDbTest');
        }

        $indexes = DB::select('PRAGMA index_list(roles)');
        $indexNames = array_column($indexes, 'name');

        $this->assertContains('roles_id_org_unique', $indexNames);
    }
}
