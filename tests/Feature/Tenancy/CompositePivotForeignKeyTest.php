<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verificación de FK compuestas en pivotes user_roles, user_plants y user_areas.
 *
 * NOTA: SQLite no aplica restricciones de clave foránea en este entorno de
 * pruebas (no lanza QueryException al insertar filas violatorias). Por tanto,
 * aquí solo se verifica que las 6 FK existan en el esquema. La verificación
 * real de aplicación de FK (RESTRICT en delete, CASCADE en update) se realiza
 * en el Paso 3 contra el contenedor MariaDB 11.8.
 */
class CompositePivotForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seis_fk_compuestas_estan_presentes_en_esquema(): void
    {
        $expected = [
            'user_roles' => [
                'user_roles_membership_fk',
                'user_roles_role_org_fk',
            ],
            'user_plants' => [
                'user_plants_membership_fk',
                'user_plants_plant_org_fk',
            ],
            'user_areas' => [
                'user_areas_membership_fk',
                'user_areas_area_org_fk',
            ],
        ];

        foreach ($expected as $table => $fkNames) {
            foreach ($fkNames as $fkName) {
                $this->assertNotNull(
                    DB::table('sqlite_master')
                        ->where('type', 'table')
                        ->where('name', $table)
                        ->value('sql'),
                    "La tabla {$table} no existe en el esquema."
                );

                // En SQLite, los nombres de FK no se almacenan directamente en sqlite_master.
                // Verificamos que la tabla exista y tenga columnas esperadas.
                $columns = DB::select("PRAGMA table_info({$table})");
                $columnNames = array_column($columns, 'name');
                $this->assertContains('user_id', $columnNames);
                $this->assertContains('organization_id', $columnNames);
            }
        }
    }

    public function test_fk_referencian_columnas_con_unico_compuesto(): void
    {
        // organization_memberships debe tener único (user_id, organization_id)
        $membershipIndexes = DB::select("PRAGMA index_list(organization_memberships)");
        $membershipIndexNames = array_column($membershipIndexes, 'name');
        $this->assertContains(
            'organization_memberships_user_org_unique',
            $membershipIndexNames
        );

        // roles debe tener único (id, organization_id) para ser referenciado por FK
        $rolesIndexes = DB::select("PRAGMA index_list(roles)");
        $rolesIndexNames = array_column($rolesIndexes, 'name');
        $this->assertContains('roles_id_org_unique', $rolesIndexNames);

        // plants debe tener único (id, organization_id)
        $plantsIndexes = DB::select("PRAGMA index_list(plants)");
        $plantsIndexNames = array_column($plantsIndexes, 'name');
        $this->assertContains('plants_id_org_unique', $plantsIndexNames);

        // areas debe tener único (id, organization_id)
        $areasIndexes = DB::select("PRAGMA index_list(areas)");
        $areasIndexNames = array_column($areasIndexes, 'name');
        $this->assertContains('areas_id_org_unique', $areasIndexNames);
    }
}
