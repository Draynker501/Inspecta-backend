<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Plant;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class OrganizationUniquenessTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_dos_organizaciones_pueden_tener_rol_mismo_nombre(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        Role::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $orgA->id,
            'name' => 'Inspector',
            'description' => 'Rol Inspector de ORG-A',
            'status' => true,
        ]);

        // Mismo nombre en otra organización debe ser aceptado
        $this->assertTrue(
            Role::where('organization_id', $orgB->id)
                ->where('name', 'Inspector')
                ->doesntExist()
        );

        Role::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $orgB->id,
            'name' => 'Inspector',
            'description' => 'Rol Inspector de ORG-B',
            'status' => true,
        ]);

        $this->assertTrue(
            Role::where('organization_id', $orgB->id)
                ->where('name', 'Inspector')
                ->exists()
        );
    }

    public function test_rol_duplicado_dentro_de_misma_organizacion_falla(): void
    {
        $organization = $this->organization('ORG-A');

        Role::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'name' => 'Inspector',
            'description' => 'Rol Inspector',
            'status' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Role::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'name' => 'Inspector',
            'description' => 'Duplicado',
            'status' => true,
        ]);
    }

    public function test_dos_organizaciones_pueden_tener_planta_mismo_codigo(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        Plant::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $orgA->id,
            'name' => 'Planta Norte',
            'code' => 'PLN-01',
            'address' => null,
            'description' => null,
            'status' => true,
        ]);

        $this->assertTrue(
            Plant::where('organization_id', $orgB->id)
                ->where('code', 'PLN-01')
                ->doesntExist()
        );

        Plant::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $orgB->id,
            'name' => 'Planta Norte B',
            'code' => 'PLN-01',
            'address' => null,
            'description' => null,
            'status' => true,
        ]);

        $this->assertTrue(
            Plant::where('organization_id', $orgB->id)
                ->where('code', 'PLN-01')
                ->exists()
        );
    }

    public function test_planta_codigo_duplicado_dentro_de_misma_organizacion_falla(): void
    {
        $organization = $this->organization('ORG-A');

        Plant::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'name' => 'Planta Norte',
            'code' => 'PLN-01',
            'address' => null,
            'description' => null,
            'status' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Plant::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'name' => 'Planta Norte 2',
            'code' => 'PLN-01',
            'address' => null,
            'description' => null,
            'status' => true,
        ]);
    }

    public function test_unicos_globales_ya_no_existen(): void
    {
        $indexesRoles = DB::table('sqlite_master')
            ->where('type', 'index')
            ->where('tbl_name', 'roles')
            ->pluck('name')
            ->toArray();

        $indexesPlants = DB::table('sqlite_master')
            ->where('type', 'index')
            ->where('tbl_name', 'plants')
            ->pluck('name')
            ->toArray();

        $this->assertNotContains('roles_name_unique', $indexesRoles);
        $this->assertNotContains('plants_code_unique', $indexesPlants);
        $this->assertContains('roles_org_name_unique', $indexesRoles);
        $this->assertContains('plants_org_code_unique', $indexesPlants);
    }
}
