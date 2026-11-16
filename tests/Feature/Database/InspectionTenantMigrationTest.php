<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InspectionTenantMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Estas pruebas son exclusivas de SQLite :memory:.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_18_000001_enforce_inspection_location_tenant_foreign_keys.php');
    }

    private function seedData(): void
    {
        foreach (['1', '2'] as $n) {
            DB::table('organizations')->insert(['id' => "o$n", 'name' => "Org $n", 'code' => "O$n", 'type' => 'client', 'status' => 1]);
            DB::table('plants')->insert(['id' => "p$n", 'organization_id' => "o$n", 'name' => "Plant $n", 'code' => "P$n", 'status' => 1]);
            DB::table('areas')->insert(['id' => "a$n", 'plant_id' => "p$n", 'organization_id' => "o$n", 'name' => "Area $n", 'code' => "A$n", 'status' => 1]);
            DB::table('production_lines')->insert(['id' => "l$n", 'area_id' => "a$n", 'organization_id' => "o$n", 'name' => "Line $n", 'code' => "L$n", 'status' => 1]);
            DB::table('users')->insert(['id' => "u$n", 'organization_id' => "o$n", 'name' => "User $n", 'email' => "u$n@example.test", 'password' => 'test', 'status' => 1]);
            DB::table('checklists')->insert(['id' => "c$n", 'organization_id' => "o$n", 'name' => "Checklist $n", 'status' => 1]);
            DB::table('checklist_versions')->insert(['id' => "v$n", 'organization_id' => "o$n", 'checklist_id' => "c$n", 'version' => '1', 'created_by' => "u$n"]);
        }
        DB::table('plants')->insert(['id' => 'p3', 'organization_id' => 'o1', 'name' => 'Other', 'code' => 'P3', 'status' => 1]);
        DB::table('areas')->insert(['id' => 'a3', 'plant_id' => 'p3', 'organization_id' => 'o1', 'name' => 'Other', 'code' => 'A3', 'status' => 1]);
        DB::table('production_lines')->insert(['id' => 'l3', 'area_id' => 'a3', 'organization_id' => 'o1', 'name' => 'Other', 'code' => 'L3', 'status' => 1]);
    }

    private function row(array $overrides = []): array
    {
        return array_replace(['id' => 'i1', 'organization_id' => 'o1', 'user_id' => 'u1', 'checklist_version_id' => 'v1', 'plant_id' => 'p1', 'area_id' => 'a1', 'production_line_id' => 'l1'], $overrides);
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('La operación debía violar una FK SQL.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('FOREIGN KEY', $e->getMessage());
        }
    }

    private function healthy(): void
    {
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame('ok', DB::scalar('PRAGMA integrity_check'));
    }

    public function test_sql_rechaza_cruces_y_preserva_linea_nullable(): void
    {
        $this->seedData();
        DB::table('inspections')->insert($this->row());
        DB::table('inspections')->insert($this->row(['id' => 'null', 'production_line_id' => null]));
        foreach ([
            ['checklist_version_id' => 'v2'], ['plant_id' => 'p2'],
            ['area_id' => 'a2'], ['area_id' => 'a3'],
            ['production_line_id' => 'l2'], ['production_line_id' => 'l3'],
        ] as $invalid) {
            $this->rejects(fn () => DB::table('inspections')->insert($this->row(['id' => 'bad'] + $invalid)));
            $this->rejects(fn () => DB::table('inspections')->where('id', 'i1')->update($invalid));
        }
        foreach (['checklist_versions' => 'v1', 'plants' => 'p1', 'areas' => 'a1', 'production_lines' => 'l1'] as $table => $id) {
            $this->rejects(fn () => DB::table($table)->where('id', $id)->update(['id' => 'changed']));
            $this->rejects(fn () => DB::table($table)->where('id', $id)->delete());
        }
        $this->healthy();
    }

    public function test_up_down_up_conserva_filas_indices_y_fk_entrantes(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();
        DB::table('inspections')->insert($this->row());
        DB::table('incidents')->insert(['id' => 'inc1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'reported_by' => 'u1', 'plant_id' => 'p1', 'area_id' => 'a1', 'production_line_id' => 'l1', 'title' => 'Incident', 'description' => 'Incident', 'risk_level' => 'low']);
        DB::statement('CREATE INDEX "extra_inspection_idx" ON "inspections" ("status")');
        $rows = DB::table('inspections')->get()->toArray();
        $incidents = DB::table('incidents')->get()->toArray();
        $indexes = DB::select("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='inspections' ORDER BY name");
        foreach (['up', 'down', 'up'] as $direction) {
            $migration->$direction();
            $this->assertEquals($rows, DB::table('inspections')->get()->toArray());
            $this->assertEquals($incidents, DB::table('incidents')->get()->toArray());
            $this->assertEquals($indexes, DB::select("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='inspections' ORDER BY name"));
            $this->rejects(fn () => DB::table('inspections')->where('id', 'i1')->delete());
            $this->healthy();
        }
    }

    public function test_preflight_rechaza_datos_inconsistentes_sin_repararlos(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();
        foreach ([
            ['checklist_version_id' => 'v2'], ['plant_id' => 'p2'],
            ['area_id' => 'a2', 'production_line_id' => null],
            ['area_id' => 'a3', 'production_line_id' => null],
            ['production_line_id' => 'l2'], ['production_line_id' => 'l3'],
        ] as $invalid) {
            DB::table('inspections')->insert($this->row($invalid));
            $schema = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');
            $rows = DB::table('inspections')->get()->toArray();
            try {
                $migration->up();
                $this->fail('Se esperaba rechazo del preflight.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('históricas', $e->getMessage());
            }
            $this->assertEquals($schema, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
            $this->assertEquals($rows, DB::table('inspections')->get()->toArray());
            $this->healthy();
            DB::table('inspections')->delete();
        }
    }

    public function test_rechaza_transaccion_externa_y_no_cambia_pragma(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::beginTransaction();
        try {
            try {
                $migration->up();
                $this->fail('Se esperaba rechazo de transacción externa.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('transacción externa', $e->getMessage());
                $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
            }
        } finally {
            DB::rollBack();
        }
        $this->healthy();
    }

    public function test_vistas_y_triggers_se_rechazan_sin_perderlos(): void
    {
        $migration = $this->migration();
        $migration->down();
        foreach ([
            'CREATE VIEW dec16_probe AS SELECT id FROM inspections',
            'CREATE TRIGGER dec16_probe AFTER INSERT ON inspections BEGIN SELECT 1; END',
        ] as $ddl) {
            DB::statement($ddl);
            $before = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');
            try {
                $migration->up();
                $this->fail('Se esperaba rechazo antes de reconstruir la tabla.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('vistas, triggers', $e->getMessage());
            }
            $this->assertEquals($before, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
            $this->healthy();
            DB::statement(str_starts_with($ddl, 'CREATE VIEW') ? 'DROP VIEW dec16_probe' : 'DROP TRIGGER dec16_probe');
        }
    }

    public function test_fallo_durante_reconstruccion_revierte_ddl_y_restaura_pragma(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();
        DB::table('inspections')->insert($this->row());
        $before = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');
        $rows = DB::table('inspections')->get()->toArray();
        $active = true;
        DB::listen(function ($query) use (&$active): void {
            if ($active && $query->sql === 'DROP TABLE "inspections"') {
                $active = false;
                throw new \RuntimeException('fallo inducido');
            }
        });
        try {
            $migration->up();
            $this->fail('Se esperaba fallo de reconstrucción.');
        } catch (\RuntimeException $e) {
            $this->assertSame('fallo inducido', $e->getMessage());
        } finally {
            $active = false;
        }
        $this->assertEquals($before, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
        $this->assertEquals($rows, DB::table('inspections')->get()->toArray());
        $this->healthy();
    }
}
