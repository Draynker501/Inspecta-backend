<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompositePivotForeignKeyMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private function isMariaDb(): bool
    {
        $driver = DB::connection()->getDriverName();

        return in_array($driver, ['mariadb', 'mysql'], true);
    }

    private function skipIfNotMariaDb(): void
    {
        if (! $this->isMariaDb()) {
            $this->markTestSkipped('Verificación equivalente en SQLite: CompositePivotForeignKeyTest');
        }
    }

    public function test_seis_fk_compuestas_estan_presentes_en_esquema(): void
    {
        $this->skipIfNotMariaDb();

        $expected = [
            'user_roles' => [
                'user_roles_user_id_organization_id_foreign' => [
                    'local' => ['user_id', 'organization_id'],
                    'ref_table' => 'organization_memberships',
                    'ref' => ['user_id', 'organization_id'],
                ],
                'user_roles_role_id_organization_id_foreign' => [
                    'local' => ['role_id', 'organization_id'],
                    'ref_table' => 'roles',
                    'ref' => ['id', 'organization_id'],
                ],
            ],
            'user_plants' => [
                'user_plants_user_id_organization_id_foreign' => [
                    'local' => ['user_id', 'organization_id'],
                    'ref_table' => 'organization_memberships',
                    'ref' => ['user_id', 'organization_id'],
                ],
                'user_plants_plant_id_organization_id_foreign' => [
                    'local' => ['plant_id', 'organization_id'],
                    'ref_table' => 'plants',
                    'ref' => ['id', 'organization_id'],
                ],
            ],
            'user_areas' => [
                'user_areas_user_id_organization_id_foreign' => [
                    'local' => ['user_id', 'organization_id'],
                    'ref_table' => 'organization_memberships',
                    'ref' => ['user_id', 'organization_id'],
                ],
                'user_areas_area_id_organization_id_foreign' => [
                    'local' => ['area_id', 'organization_id'],
                    'ref_table' => 'areas',
                    'ref' => ['id', 'organization_id'],
                ],
            ],
        ];

        foreach ($expected as $table => $constraints) {
            foreach ($constraints as $constraint => $spec) {
                $rows = DB::select(
                    'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                     FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?
                       AND CONSTRAINT_NAME = ?
                     ORDER BY ORDINAL_POSITION',
                    [$table, $constraint]
                );

                $this->assertCount(
                    count($spec['local']),
                    $rows,
                    "La FK {$constraint} en {$table} no tiene el número esperado de columnas."
                );

                $local = array_column($rows, 'COLUMN_NAME');
                $refTables = array_unique(array_column($rows, 'REFERENCED_TABLE_NAME'));
                $ref = array_column($rows, 'REFERENCED_COLUMN_NAME');

                $this->assertSame($spec['local'], $local, "Columnas locales incorrectas en {$constraint}.");
                $this->assertSame([$spec['ref_table']], array_values($refTables), "Tabla referenciada incorrecta en {$constraint}.");
                $this->assertSame($spec['ref'], $ref, "Columnas referenciadas incorrectas en {$constraint}.");
            }
        }
    }

    public function test_fk_referencian_columnas_con_unico_compuesto(): void
    {
        $this->skipIfNotMariaDb();

        $cases = [
            'organization_memberships' => [
                'organization_memberships_user_org_unique' => ['user_id', 'organization_id'],
            ],
            'roles' => [
                'roles_id_org_unique' => ['id', 'organization_id'],
            ],
            'plants' => [
                'plants_id_org_unique' => ['id', 'organization_id'],
            ],
            'areas' => [
                'areas_id_org_unique' => ['id', 'organization_id'],
            ],
        ];

        foreach ($cases as $table => $indexes) {
            foreach ($indexes as $indexName => $columns) {
                $rows = DB::select(
                    'SELECT COLUMN_NAME
                     FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?
                       AND INDEX_NAME = ?
                       AND NON_UNIQUE = 0
                     ORDER BY SEQ_IN_INDEX',
                    [$table, $indexName]
                );

                $this->assertNotEmpty($rows, "El índice {$indexName} en {$table} no existe o no es único.");
                $this->assertSame($columns, array_column($rows, 'COLUMN_NAME'), "Columnas del índice {$indexName} incorrectas.");
            }
        }
    }
}
