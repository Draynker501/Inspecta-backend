<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Requiere phpunit.mariadb.xml, MariaDB 11.8 aislada y TestDatabaseGuard.
 * migrate:fresh destruye el esquema de la base de pruebas autorizada.
 */
class AreaPlantTenantMigrationMariaDbTest extends TestCase
{
    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Pruebas exclusivas de MariaDB.');
        }

        $this->assertSame('testing', app()->environment());
        $this->assertContains($connection->getConfig('host'), ['127.0.0.1', 'localhost']);
        $this->assertStringEndsWith('_test', $connection->getDatabaseName());
        $this->assertTrue(filter_var(getenv('TESTING_DB_ALLOW_EXTERNAL'), FILTER_VALIDATE_BOOLEAN));
        $version = (string) $connection->selectOne('SELECT VERSION() AS version')->version;
        $this->assertMatchesRegularExpression('/(?:^|[^0-9])11\\.8\\.[0-9]+.*MariaDB/i', $version);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_14_000001_replace_areas_plant_foreign_key.php');
    }

    private function seedData(): void
    {
        DB::table('organizations')->insert([
            ['id' => 'o1', 'name' => 'One', 'code' => 'O1', 'type' => 'client', 'status' => 1],
            ['id' => 'o2', 'name' => 'Two', 'code' => 'O2', 'type' => 'client', 'status' => 1],
        ]);
        DB::table('plants')->insert([
            ['id' => 'p1', 'organization_id' => 'o1', 'name' => 'One', 'code' => 'P1', 'status' => 1],
            ['id' => 'p2', 'organization_id' => 'o2', 'name' => 'Two', 'code' => 'P2', 'status' => 1],
        ]);
    }

    private function area(string $id, string $plant, string $org, ?string $deleted = null): array
    {
        return [
            'id' => $id, 'organization_id' => $org, 'plant_id' => $plant, 'name' => $id,
            'code' => $id, 'status' => 1, 'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2021-01-01 00:00:00', 'deleted_at' => $deleted,
        ];
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());

            return;
        }
        $this->fail('Se esperaba un rechazo SQL.');
    }

    private function keys(): array
    {
        return DB::select("SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.ORDINAL_POSITION,
                                  k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                                  r.UPDATE_RULE, r.DELETE_RULE
                           FROM information_schema.KEY_COLUMN_USAGE k
                           JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                             ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
                            AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
                           WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='areas'
                             AND k.REFERENCED_TABLE_NAME IS NOT NULL
                           ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION");
    }

    private function indexes(): array
    {
        return DB::select("SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
                           FROM information_schema.STATISTICS
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='areas'
                           ORDER BY INDEX_NAME, SEQ_IN_INDEX");
    }

    public function test_instalacion_desde_cero_y_restricciones(): void
    {
        $this->seedData();
        DB::table('areas')->insert($this->area('a1', 'p1', 'o1'));
        $this->rejects(fn () => DB::table('areas')->insert($this->area('bad', 'p2', 'o1')));
        $this->rejects(fn () => DB::table('areas')->insert($this->area('bad', 'missing', 'o1')));
        $this->rejects(fn () => DB::table('plants')->where('id', 'p1')->update(['id' => 'p3']));
        $this->rejects(fn () => DB::table('plants')->where('id', 'p1')->update(['organization_id' => 'o2']));
        $this->rejects(fn () => DB::table('plants')->where('id', 'p1')->delete());
        $foreign = array_values(array_filter($this->keys(), fn ($key) => $key->CONSTRAINT_NAME === 'areas_plant_org_fk'));
        $this->assertCount(2, $foreign);
        $this->assertSame(['plant_id', 'organization_id'], array_column($foreign, 'COLUMN_NAME'));
        $this->assertSame(['id', 'organization_id'], array_column($foreign, 'REFERENCED_COLUMN_NAME'));
        $this->assertSame(['plants', 'plants'], array_column($foreign, 'REFERENCED_TABLE_NAME'));
        $this->assertSame(['RESTRICT', 'RESTRICT'], array_column($foreign, 'UPDATE_RULE'));
        $this->assertSame(['RESTRICT', 'RESTRICT'], array_column($foreign, 'DELETE_RULE'));
        $index = array_values(array_filter($this->indexes(), fn ($row) => $row->INDEX_NAME === 'areas_plant_org_idx'));
        $this->assertSame(['plant_id', 'organization_id'], array_column($index, 'COLUMN_NAME'));
        $this->assertCount(0, array_filter($this->keys(), fn ($key) => $key->CONSTRAINT_NAME === 'areas_plant_id_foreign'));
        $this->assertCount(1, array_filter($this->keys(), fn ($key) => $key->CONSTRAINT_NAME === 'areas_organization_id_foreign'));
    }

    public function test_up_down_conserva_datos_referencias_indices_y_cascade_historico(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();
        DB::table('areas')->insert([$this->area('a1', 'p1', 'o1'), $this->area('a2', 'p1', 'o1', '2022-01-01 00:00:00')]);
        DB::table('production_lines')->insert(['id' => 'l1', 'area_id' => 'a1', 'organization_id' => 'o1', 'name' => 'Line', 'code' => 'L1', 'status' => 1]);
        DB::table('users')->insert(['id' => 'u1', 'organization_id' => 'o1', 'name' => 'User', 'email' => 'u@example.test', 'password' => 'test', 'status' => 1]);
        DB::table('organization_memberships')->insert(['id' => 'm1', 'user_id' => 'u1', 'organization_id' => 'o1', 'status' => 'active']);
        DB::table('user_areas')->insert(['id' => 'ua1', 'user_id' => 'u1', 'area_id' => 'a1', 'organization_id' => 'o1']);
        DB::table('checklists')->insert(['id' => 'c1', 'organization_id' => 'o1', 'name' => 'Checklist', 'status' => 1]);
        DB::table('checklist_versions')->insert(['id' => 'cv1', 'organization_id' => 'o1', 'checklist_id' => 'c1', 'version' => '1', 'created_by' => 'u1']);
        DB::table('inspections')->insert(['id' => 'i1', 'organization_id' => 'o1', 'user_id' => 'u1', 'checklist_version_id' => 'cv1', 'plant_id' => 'p1', 'area_id' => 'a1', 'production_line_id' => 'l1']);
        DB::table('incidents')->insert(['id' => 'inc1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'reported_by' => 'u1', 'plant_id' => 'p1', 'area_id' => 'a1', 'production_line_id' => 'l1', 'title' => 'Incident', 'description' => 'Incident', 'risk_level' => 'low']);
        $dependent = [];
        foreach (['production_lines', 'user_areas', 'inspections', 'incidents'] as $table) {
            $dependent[$table] = DB::table($table)->get()->toArray();
        }
        $before = DB::table('areas')->orderBy('id')->get()->toArray();
        $indexes = $this->indexes();
        $keys = $this->keys();
        $migration->up();
        $this->assertEquals($before, DB::table('areas')->orderBy('id')->get()->toArray());
        foreach ($dependent as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->get()->toArray());
        }
        $this->rejects(fn () => DB::table('plants')->where('id', 'p1')->update(['id' => 'p3']));
        $migration->down();
        $this->assertEquals($before, DB::table('areas')->orderBy('id')->get()->toArray());
        foreach ($dependent as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->get()->toArray());
        }
        $this->assertEquals($keys, $this->keys());
        $this->assertEquals($indexes, $this->indexes());
        DB::table('plants')->where('id', 'p1')->update(['id' => 'p3']);
        $this->assertSame(2, DB::table('areas')->where('plant_id', 'p3')->count());
        $migration->up();
        $this->assertCount(2, array_filter($this->keys(), fn ($key) => $key->CONSTRAINT_NAME === 'areas_plant_org_fk'));
        $this->assertCount(2, array_filter($this->indexes(), fn ($index) => $index->INDEX_NAME === 'areas_plant_org_idx'));
        $this->rejects(fn () => DB::table('plants')->where('id', 'p3')->update(['id' => 'p4']));
    }

    public function test_preflight_rechaza_datos_inconsistentes_sin_cambios(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();
        DB::table('areas')->insert($this->area('bad', 'p2', 'o1', '2022-01-01 00:00:00'));
        $before = DB::table('areas')->get()->toArray();
        $keys = $this->keys();
        $indexes = $this->indexes();
        try {
            $migration->up();
            $this->fail('El preflight debía rechazar datos inconsistentes.');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertEquals($before, DB::table('areas')->get()->toArray());
        $this->assertEquals($keys, $this->keys());
        $this->assertEquals($indexes, $this->indexes());
    }

    public function test_ddl_fallido_no_retira_fk_historica(): void
    {
        $this->migration()->down();
        $before = $this->keys();
        $indexes = $this->indexes();
        $this->rejects(fn () => DB::statement('ALTER TABLE areas DROP FOREIGN KEY areas_plant_id_foreign, ADD CONSTRAINT cat02_invalid_fk FOREIGN KEY (plant_id) REFERENCES cat02_nonexistent (id)'));
        $this->assertEquals($before, $this->keys());
        $this->assertEquals($indexes, $this->indexes());
    }

    public function test_alter_real_con_algoritmo_inplace_rechazado_conserva_restricciones(): void
    {
        $this->migration()->down();
        $keys = $this->keys();
        $indexes = $this->indexes();
        try {
            DB::statement('ALTER TABLE `areas` DROP FOREIGN KEY `areas_plant_id_foreign`, ADD INDEX `areas_plant_org_idx` (`plant_id`, `organization_id`), ADD CONSTRAINT `areas_plant_org_fk` FOREIGN KEY (`plant_id`, `organization_id`) REFERENCES `plants` (`id`, `organization_id`) ON DELETE RESTRICT ON UPDATE RESTRICT, ALGORITHM=INPLACE');
            $this->fail('Se esperaba rechazo de INPLACE con foreign_key_checks activado.');
        } catch (QueryException $e) {
            $this->assertSame(1846, (int) $e->errorInfo[1]);
        }
        $this->assertEquals($keys, $this->keys());
        $this->assertEquals($indexes, $this->indexes());
    }

    public function test_esquema_aplicado_sin_registro_no_se_repara_automaticamente(): void
    {
        $name = '2026_11_14_000001_replace_areas_plant_foreign_key';
        $this->assertSame(1, DB::table('migrations')->where('migration', $name)->delete());
        $keys = $this->keys();
        $indexes = $this->indexes();
        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->fail('El estado ambiguo debía rechazarse.');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertEquals($keys, $this->keys());
        $this->assertEquals($indexes, $this->indexes());
        $this->assertFalse(DB::table('migrations')->where('migration', $name)->exists());
    }
}
