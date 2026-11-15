<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AreaPlantTenantMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Estas pruebas son específicas de SQLite.');
        }
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertFalse(filter_var(getenv('TESTING_DB_ALLOW_EXTERNAL'), FILTER_VALIDATE_BOOLEAN));
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    private function migration(): object
    {
        $files = glob(database_path('migrations/*replace_areas_plant_foreign_key.php'));
        $this->assertCount(1, $files);

        return require $files[0];
    }

    private function seedParents(): void
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
        return ['id' => $id, 'plant_id' => $plant, 'organization_id' => $org, 'name' => $id,
            'code' => $id, 'status' => 1, 'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2021-01-01 00:00:00', 'deleted_at' => $deleted];
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());

            return;
        }

        $this->fail('La operación debía rechazarse mediante una restricción SQL.');
    }

    private function healthy(): void
    {
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame('ok', (string) array_values((array) DB::selectOne('PRAGMA integrity_check'))[0]);
        $this->assertSame(1, (int) array_values((array) DB::selectOne('PRAGMA foreign_keys'))[0]);
    }

    public function test_migrador_desde_cero_restringe_relaciones_y_operaciones_del_padre(): void
    {
        $this->seedParents();
        DB::table('areas')->insert($this->area('a1', 'p1', 'o1'));
        $this->rejected(fn () => DB::table('areas')->insert($this->area('bad', 'p2', 'o1')));
        $this->rejected(fn () => DB::table('areas')->insert($this->area('bad', 'missing', 'o1')));
        $this->rejected(fn () => DB::table('plants')->where('id', 'p1')->update(['id' => 'changed']));
        $this->rejected(fn () => DB::table('plants')->where('id', 'p1')->update(['organization_id' => 'o2']));
        $this->rejected(fn () => DB::table('plants')->where('id', 'p1')->delete());
        $this->healthy();
    }

    public function test_up_down_conservan_filas_e_indices_y_restauran_cascade(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedParents();
        DB::table('areas')->insert([
            $this->area('a1', 'p1', 'o1'),
            $this->area('a2', 'p1', 'o1', '2022-01-01 00:00:00'),
        ]);
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
        $indexes = DB::select("SELECT name, sql FROM sqlite_schema WHERE type='index' AND tbl_name='areas' ORDER BY name");
        $migration->up();
        $this->assertEquals($before, DB::table('areas')->orderBy('id')->get()->toArray());
        $this->assertSame('a1', DB::table('production_lines')->where('id', 'l1')->value('area_id'));
        foreach ($dependent as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->get()->toArray());
        }
        $this->rejected(fn () => DB::table('plants')->where('id', 'p1')->update(['id' => 'p3']));
        $this->healthy();
        $migration->down();
        $this->assertEquals($before, DB::table('areas')->orderBy('id')->get()->toArray());
        $this->assertEquals($indexes, DB::select("SELECT name, sql FROM sqlite_schema WHERE type='index' AND tbl_name='areas' ORDER BY name"));
        foreach ($dependent as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->get()->toArray());
        }
        DB::table('plants')->where('id', 'p1')->update(['id' => 'p3']);
        $this->assertSame(2, DB::table('areas')->where('plant_id', 'p3')->count());
        $this->healthy();
    }

    public function test_datos_historicos_inconsistentes_abortan_sin_alterar_datos_ni_esquema(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedParents();
        DB::table('areas')->insert($this->area('bad', 'p2', 'o1', '2022-01-01'));
        $schema = DB::select('SELECT type,name,sql FROM sqlite_schema ORDER BY type,name');
        $rows = DB::table('areas')->get()->toArray();
        try {
            $migration->up();
            $this->fail('La migración debía rechazar los datos históricos.');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertEquals($schema, DB::select('SELECT type,name,sql FROM sqlite_schema ORDER BY type,name'));
        $this->assertEquals($rows, DB::table('areas')->get()->toArray());
        $this->healthy();
    }

    public function test_transaccion_externa_se_rechaza_sin_cambiar_el_pragma(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::beginTransaction();
        try {
            try {
                $migration->up();
                $this->fail('Se esperaba rechazo de una transacción externa.');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
                $this->assertSame(1, (int) array_values((array) DB::selectOne('PRAGMA foreign_keys'))[0]);
            }
        } finally {
            DB::rollBack();
        }
        $this->healthy();
    }

    public function test_vista_desconocida_no_se_elimina_silenciosamente(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement('CREATE VIEW areas_external_probe AS SELECT id FROM areas');
        $before = DB::select('SELECT type,name,sql FROM sqlite_schema ORDER BY type,name');
        try {
            $migration->up();
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
            $this->assertEquals($before, DB::select('SELECT type,name,sql FROM sqlite_schema ORDER BY type,name'));
            $this->healthy();

            return;
        }
        $this->assertCount(1, DB::select("SELECT name FROM sqlite_schema WHERE name='areas_external_probe'"));
        DB::select('SELECT * FROM areas_external_probe');
        $this->healthy();
    }

    public function test_fallo_durante_reconstruccion_revierte_esquema_y_datos(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedParents();
        DB::table('areas')->insert($this->area('a1', 'p1', 'o1'));
        $before = DB::select('SELECT type,name,sql FROM sqlite_schema ORDER BY type,name');
        $active = true;
        DB::listen(function ($query) use (&$active): void {
            if ($active && $query->sql === 'DROP TABLE "areas"') {
                $active = false;
                throw new \RuntimeException('fallo inducido');
            }
        });
        try {
            $migration->up();
            $this->fail('La reconstrucción debía fallar.');
        } catch (\RuntimeException $e) {
            $this->assertSame('fallo inducido', $e->getMessage());
        } finally {
            $active = false;
        }
        $this->assertEquals($before, DB::select('SELECT type,name,sql FROM sqlite_schema ORDER BY type,name'));
        $this->assertSame('p1', DB::table('areas')->where('id', 'a1')->value('plant_id'));
        $this->healthy();
    }

    public function test_trigger_desconocido_se_rechaza_sin_cambiar_esquema(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement('CREATE TRIGGER areas_probe AFTER INSERT ON areas BEGIN SELECT 1; END');
        $before = DB::select('SELECT type,name,sql FROM sqlite_schema ORDER BY type,name');
        try {
            $migration->up();
            $this->fail('El trigger desconocido debía rechazarse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('triggers', $e->getMessage());
        }
        $this->assertEquals($before, DB::select('SELECT type,name,sql FROM sqlite_schema ORDER BY type,name'));
        $this->healthy();
    }

    public function test_indice_candidato_ausente_se_rechaza_sin_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement('DROP INDEX plants_id_org_unique');
        $before = DB::select('SELECT type,name,sql FROM sqlite_schema ORDER BY type,name');
        try {
            $migration->up();
            $this->fail('La migración debía rechazar la clave candidata ausente.');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertEquals($before, DB::select('SELECT type,name,sql FROM sqlite_schema ORDER BY type,name'));
        $this->assertSame(1, (int) array_values((array) DB::selectOne('PRAGMA foreign_keys'))[0]);
    }

    public function test_driver_no_soportado_aborta_antes_de_ddl(): void
    {
        $migration = $this->migration();
        $original = DB::getFacadeRoot();
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->twice()->andReturn('pgsql');
        $manager = \Mockery::mock(DatabaseManager::class);
        $manager->shouldReceive('connection')->once()->andReturn($connection);
        DB::swap($manager);
        try {
            $migration->up();
            $this->fail('El driver no soportado debía rechazarse.');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        } finally {
            DB::swap($original);
        }
    }

    public function test_driver_mysql_con_servidor_no_mariadb_se_rechaza_antes_de_ddl(): void
    {
        $migration = $this->migration();
        $original = DB::getFacadeRoot();
        $pdo = \Mockery::mock(\PDO::class);
        $pdo->shouldReceive('inTransaction')->once()->andReturn(false);
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('');
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldReceive('selectOne')->once()->andReturn((object) [
            'version' => '8.0.0 MySQL', 'schema_name' => 'db_test', 'fk_checks' => 1,
        ]);
        $manager = \Mockery::mock(DatabaseManager::class);
        $manager->shouldReceive('connection')->once()->andReturn($connection);
        DB::swap($manager);
        try {
            $migration->up();
            $this->fail('MySQL debía rechazarse antes de DDL.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MariaDB 11.8', $e->getMessage());
        } finally {
            DB::swap($original);
        }
    }
}
