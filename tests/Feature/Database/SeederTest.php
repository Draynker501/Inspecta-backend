<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Conteos esperados tras ejecutar DatabaseSeeder.
     *
     * @var array<string, int>
     */
    private const EXPECTED_COUNTS = [
        'organizations' => 1,
        'roles' => 3,
        'plants' => 2,
        'areas' => 3,
        'production_lines' => 2,
        'users' => 3,
        'user_roles' => 3,
        'user_plants' => 3,
        'user_areas' => 3,
        'checklists' => 1,
        'norms' => 1,
    ];

    public function test_los_seeders_son_idempotentes_en_conteos(): void
    {
        $this->seed();
        $this->seed();

        foreach (self::EXPECTED_COUNTS as $table => $expected) {
            $this->assertSame(
                $expected,
                DB::table($table)->count(),
                "Conteo inesperado en la tabla {$table} tras dos siembras."
            );
        }
    }

    public function test_los_pivotes_y_las_contrasenas_conservan_sus_valores_entre_siembras(): void
    {
        $this->seed();

        $pivotsBefore = $this->pivotIds();
        $passwordBefore = DB::table('users')
            ->where('email', 'admin@inspecta-demo.com')
            ->value('password');

        $this->seed();

        $this->assertSame($pivotsBefore, $this->pivotIds());
        $this->assertSame(
            $passwordBefore,
            DB::table('users')->where('email', 'admin@inspecta-demo.com')->value('password'),
            'La contraseña sembrada no debe regenerarse al re-sembrar.'
        );
    }

    public function test_todas_las_filas_sembradas_pertenecen_a_la_organizacion_demo(): void
    {
        $this->seed();

        $organizationId = DB::table('organizations')->where('code', 'INSP-01')->value('id');

        $this->assertNotNull($organizationId, 'No se creó la organización INSP-01.');

        foreach (['users', 'roles', 'plants', 'areas', 'production_lines', 'checklists', 'norms', 'user_roles', 'user_plants', 'user_areas'] as $table) {
            $this->assertSame(
                0,
                DB::table($table)->where('organization_id', '!=', $organizationId)->count(),
                "La tabla {$table} contiene filas de otra organización."
            );
        }
    }

    public function test_los_pivotes_son_coherentes_con_usuario_recurso_y_organizacion(): void
    {
        $this->seed();

        $userRoleMismatches = DB::table('user_roles')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where(function ($query) {
                $query->whereColumn('user_roles.organization_id', '!=', 'users.organization_id')
                    ->orWhereColumn('user_roles.organization_id', '!=', 'roles.organization_id');
            })
            ->count();

        $userPlantMismatches = DB::table('user_plants')
            ->join('users', 'users.id', '=', 'user_plants.user_id')
            ->join('plants', 'plants.id', '=', 'user_plants.plant_id')
            ->where(function ($query) {
                $query->whereColumn('user_plants.organization_id', '!=', 'users.organization_id')
                    ->orWhereColumn('user_plants.organization_id', '!=', 'plants.organization_id');
            })
            ->count();

        $userAreaMismatches = DB::table('user_areas')
            ->join('users', 'users.id', '=', 'user_areas.user_id')
            ->join('areas', 'areas.id', '=', 'user_areas.area_id')
            ->where(function ($query) {
                $query->whereColumn('user_areas.organization_id', '!=', 'users.organization_id')
                    ->orWhereColumn('user_areas.organization_id', '!=', 'areas.organization_id');
            })
            ->count();

        $this->assertSame(0, $userRoleMismatches, 'Hay asignaciones de rol con organización incoherente.');
        $this->assertSame(0, $userPlantMismatches, 'Hay asignaciones de planta con organización incoherente.');
        $this->assertSame(0, $userAreaMismatches, 'Hay asignaciones de área con organización incoherente.');
    }

    public function test_los_seeders_no_dejan_referencias_huerfanas(): void
    {
        $this->seed();

        $orphans = [
            'users->organizations' => DB::table('users')
                ->leftJoin('organizations', 'organizations.id', '=', 'users.organization_id')
                ->whereNull('organizations.id')
                ->count(),
            'user_roles->users' => DB::table('user_roles')
                ->leftJoin('users', 'users.id', '=', 'user_roles.user_id')
                ->whereNull('users.id')
                ->count(),
            'user_roles->roles' => DB::table('user_roles')
                ->leftJoin('roles', 'roles.id', '=', 'user_roles.role_id')
                ->whereNull('roles.id')
                ->count(),
            'user_plants->users' => DB::table('user_plants')
                ->leftJoin('users', 'users.id', '=', 'user_plants.user_id')
                ->whereNull('users.id')
                ->count(),
            'user_plants->plants' => DB::table('user_plants')
                ->leftJoin('plants', 'plants.id', '=', 'user_plants.plant_id')
                ->whereNull('plants.id')
                ->count(),
            'user_areas->users' => DB::table('user_areas')
                ->leftJoin('users', 'users.id', '=', 'user_areas.user_id')
                ->whereNull('users.id')
                ->count(),
            'user_areas->areas' => DB::table('user_areas')
                ->leftJoin('areas', 'areas.id', '=', 'user_areas.area_id')
                ->whereNull('areas.id')
                ->count(),
        ];

        foreach ($orphans as $reference => $count) {
            $this->assertSame(0, $count, "Referencia huérfana detectada: {$reference}.");
        }
    }

    public function test_las_cuentas_sembradas_pueden_autenticarse(): void
    {
        $this->seed();

        $accounts = [
            ['admin@inspecta-demo.com', 'Admin123!'],
            ['inspector@inspecta-demo.com', 'Inspector123!'],
            ['supervisor@inspecta-demo.com', 'Supervisor123!'],
        ];

        foreach ($accounts as [$email, $password]) {
            $this->postJson('/api/auth/login', compact('email', 'password'))
                ->assertOk()
                ->assertJsonPath('data.user.email', $email);
        }
    }

    /**
     * ID de cada asignación, ordenado por sus claves naturales.
     *
     * @return array<string, list<string>>
     */
    private function pivotIds(): array
    {
        return [
            'user_roles' => DB::table('user_roles')
                ->orderBy('user_id')->orderBy('role_id')->pluck('id')->all(),
            'user_plants' => DB::table('user_plants')
                ->orderBy('user_id')->orderBy('plant_id')->pluck('id')->all(),
            'user_areas' => DB::table('user_areas')
                ->orderBy('user_id')->orderBy('area_id')->pluck('id')->all(),
        ];
    }
}
