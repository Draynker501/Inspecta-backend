<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InspectionTenantMariaDbTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Solo MariaDB aislada.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->assertMatchesRegularExpression('/11\.8\.\d+.*MariaDB/i', DB::scalar('SELECT VERSION()'));
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
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

    private function row(array $changes = []): array
    {
        return array_replace(['id' => 'i1', 'organization_id' => 'o1', 'user_id' => 'u1', 'checklist_version_id' => 'v1', 'plant_id' => 'p1', 'area_id' => 'a1', 'production_line_id' => 'l1'], $changes);
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Se esperaba una violación de FK.');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
        }
    }

    public function test_datos_fk_entrantes_y_reversibilidad(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();
        DB::table('inspections')->insert($this->row());
        DB::table('inspections')->insert($this->row(['id' => 'nullable', 'production_line_id' => null]));
        DB::table('incidents')->insert(['id' => 'inc1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'reported_by' => 'u1', 'plant_id' => 'p1', 'area_id' => 'a1', 'production_line_id' => 'l1', 'title' => 'Incident', 'description' => 'Incident', 'risk_level' => 'low']);
        $rows = DB::table('inspections')->orderBy('id')->get()->toArray();
        foreach (['up', 'down', 'up'] as $direction) {
            $migration->$direction();
            $this->assertEquals($rows, DB::table('inspections')->orderBy('id')->get()->toArray());
            $this->assertSame(1, DB::table('incidents')->count());
            $this->rejects(fn () => DB::table('inspections')->where('id', 'i1')->delete());
        }
        foreach ([['checklist_version_id' => 'v2'], ['plant_id' => 'p2'], ['area_id' => 'a2'], ['area_id' => 'a3'], ['production_line_id' => 'l2'], ['production_line_id' => 'l3']] as $invalid) {
            $this->rejects(fn () => DB::table('inspections')->insert($this->row(['id' => 'bad'] + $invalid)));
            $this->rejects(fn () => DB::table('inspections')->where('id', 'i1')->update($invalid));
        }
    }

    public function test_preflight_no_repara_filas_incompatibles(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();
        foreach ([['checklist_version_id' => 'v2'], ['plant_id' => 'p2'], ['area_id' => 'a3', 'production_line_id' => null], ['production_line_id' => 'l3']] as $invalid) {
            DB::table('inspections')->insert($this->row($invalid));
            try {
                $migration->up();
                $this->fail('Se esperaba rechazo del preflight.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('históricas', $e->getMessage());
            }
            $this->assertSame(1, DB::table('inspections')->count());
            $this->assertSame(0, DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('INDEX_NAME', 'ins_cv_id_org_unique')->count());
            DB::table('inspections')->delete();
        }
    }

    public function test_preflight_rechaza_reglas_historicas_alteradas_sin_ddl_dec16(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement('ALTER TABLE `inspections` DROP FOREIGN KEY `inspections_plant_id_foreign`');
        DB::statement('ALTER TABLE `inspections` ADD CONSTRAINT `inspections_plant_id_foreign` FOREIGN KEY (`plant_id`) REFERENCES `plants` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT');
        try {
            $migration->up();
            $this->fail('Las reglas históricas alteradas no deben superar el preflight.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('FK histórica inesperada', $e->getMessage());
        }
        $this->assertSame(0, DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('INDEX_NAME', 'ins_cv_id_org_unique')->count());
        $this->assertSame('RESTRICT', DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'inspections')->where('CONSTRAINT_NAME', 'inspections_plant_id_foreign')->value('UPDATE_RULE'));
    }

    public function test_fallo_ddl_parcial_y_recuperacion_supervisada(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedData();
        DB::table('inspections')->insert($this->row());
        DB::table('incidents')->insert(['id' => 'inc1', 'organization_id' => 'o1', 'inspection_id' => 'i1', 'reported_by' => 'u1', 'plant_id' => 'p1', 'area_id' => 'a1', 'title' => 'Incident', 'description' => 'Incident', 'risk_level' => 'low']);
        $active = true;
        DB::listen(function ($query) use (&$active): void {
            if ($active && str_contains($query->sql, 'ADD UNIQUE KEY `ins_cv_id_org_unique`')) {
                $active = false;
                // Simula una colisión posterior al preflight; el siguiente ALTER falla en el motor.
                DB::statement('ALTER TABLE `areas` ADD UNIQUE KEY `ins_areas_id_plant_org_unique` (`id`, `plant_id`, `organization_id`)');
            }
        });
        try {
            $migration->up();
            $this->fail('Se esperaba fallo inducido.');
        } catch (QueryException $e) {
            $this->assertSame(1061, (int) $e->errorInfo[1]);
        } finally {
            $active = false;
        }
        $this->assertSame(1, DB::table('inspections')->count());
        $this->assertSame(1, DB::table('incidents')->count());
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_11_18_000001_enforce_inspection_location_tenant_foreign_keys')->count());
        try {
            $migration->up();
            $this->fail('El estado parcial debe impedir reintentar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('estado parcial', $e->getMessage());
        }
        $this->assertSame(1, DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('INDEX_NAME', 'ins_areas_id_plant_org_unique')->where('SEQ_IN_INDEX', 1)->count());
        DB::statement('ALTER TABLE `areas` DROP INDEX `ins_areas_id_plant_org_unique`');
        DB::statement('ALTER TABLE `checklist_versions` DROP INDEX `ins_cv_id_org_unique`');
        $migration->up();
        $this->assertSame(1, DB::table('inspections')->count());
        $this->assertSame(1, DB::table('incidents')->count());
        $this->rejects(fn () => DB::table('inspections')->where('id', 'i1')->delete());
        $this->rejects(fn () => DB::table('inspections')->where('id', 'i1')->update(['production_line_id' => 'l3']));
    }
}
