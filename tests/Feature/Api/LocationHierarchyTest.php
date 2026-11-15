<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class LocationHierarchyTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function grant(User $user, Organization $org, string $roleName, array $permissions): Role
    {
        app(TenantContext::class)->set($org->id);
        $role = $this->role($org, $roleName);
        $user->assignRole($role);
        foreach ($permissions as $permission) {
            RolePermission::firstOrCreate([
                'role_id' => $role->id,
                'organization_id' => $org->id,
                'permission' => $permission,
            ], ['id' => (string) Str::uuid7()]);
        }

        return $role;
    }

    public function test_sin_permiso_devuelve_403(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', []);
        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertForbidden();
    }

    public function test_usuario_sin_asignaciones_recibe_coleccion_vacia(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $this->plant($org, 'P');

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_usuario_con_locations_view_all_ve_toda_la_jerarquia(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Admin', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $line = $this->productionLine($area, 'L');

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertOk();
        $this->assertSame($plant->id, $response->json('data.0.id'));
        $this->assertSame($area->id, $response->json('data.0.areas.0.id'));
        $this->assertSame($line->id, $response->json('data.0.areas.0.production_lines.0.id'));
    }

    public function test_navegacion_restringida_planta_contenedora(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $otherArea = $this->area($plant, 'B');
        $line = $this->productionLine($area, 'L');
        app(TenantContext::class)->set($org->id);
        $user->assignArea($area);

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertOk();
        $this->assertSame(['id', 'name', 'code', 'status', 'areas'], array_keys($response->json('data.0')));
        $ids = collect($response->json('data.0.areas'))->pluck('id')->all();
        $this->assertSame([$area->id], $ids);
        $this->assertSame($line->id, $response->json('data.0.areas.0.production_lines.0.id'));
        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/plants/{$plant->id}")
            ->assertNotFound();
        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/areas/{$otherArea->id}")
            ->assertNotFound();
    }

    public function test_soft_deleted_ancestors_excluidos(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $line = $this->productionLine($area, 'L');
        $area->delete();

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertOk();
        $this->assertSame([], $response->json('data.0.areas'));
        $this->assertNotContains($line->id, collect($response->json('data.0.areas.*.production_lines.*.id'))->all());
    }

    public function test_filtros_plant_id_y_area_id(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');

        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/locations/hierarchy?plant_id={$plant->id}&area_id={$area->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.areas');
    }

    public function test_unknown_params_rejected(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy?foo=bar')
            ->assertStatus(422);
    }

    public function test_limite_1001_nodos_devuelve_422(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P');
        for ($i = 0; $i < 500; $i++) {
            $area = $this->area($plant, "A{$i}");
            for ($j = 0; $j < 2; $j++) {
                $this->productionLine($area, "L{$i}_{$j}");
            }
        }

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy?per_page=50')
            ->assertStatus(422);
    }

    public function test_locations_view_all_sin_plants_view_devuelve_403(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::LocationsViewAll->value]);
        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertForbidden();
    }

    public function test_plant_asignada_ve_todos_sus_descendientes(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $line = $this->productionLine($area, 'L');
        app(TenantContext::class)->set($org->id);
        $user->assignPlant($plant);

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertOk();
        $this->assertSame($plant->id, $response->json('data.0.id'));
        $this->assertSame($area->id, $response->json('data.0.areas.0.id'));
        $this->assertSame($line->id, $response->json('data.0.areas.0.production_lines.0.id'));
    }

    public function test_asignaciones_combinadas_sin_duplicados(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $plant = $this->plant($org, 'P');
        $areaA = $this->area($plant, 'A');
        $areaB = $this->area($plant, 'B');
        $lineA = $this->productionLine($areaA, 'L');
        app(TenantContext::class)->set($org->id);
        $user->assignPlant($plant);
        $user->assignArea($areaB);

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertOk();
        $areaIds = collect($response->json('data.0.areas'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$areaA->id, $areaB->id], $areaIds);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_linea_soft_deleted_excluida(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $line = $this->productionLine($area, 'L');
        $line->delete();

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertOk();
        $this->assertSame([], $response->json('data.0.areas.0.production_lines'));
    }

    public function test_recurso_inactivo_incluido(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P', ['status' => false]);
        $area = $this->area($plant, 'A', ['status' => false]);
        $line = $this->productionLine($area, 'L', ['status' => false]);

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertOk();
        $this->assertFalse($response->json('data.0.status'));
        $this->assertFalse($response->json('data.0.areas.0.status'));
        $this->assertFalse($response->json('data.0.areas.0.production_lines.0.status'));
    }

    public function test_colecciones_vacias_son_arrays(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $this->plant($org, 'P');

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertOk();
        $this->assertSame([], $response->json('data.0.areas'));
    }

    public function test_cross_tenant_no_expone_recursos(): void
    {
        $orgA = $this->clientOrganization('ORG-A');
        $orgB = $this->clientOrganization('ORG-B');
        $user = $this->user($orgA);
        $this->grant($user, $orgA, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $this->plant($orgA, 'A');
        app(TenantContext::class)->runAs($orgB->id, fn () => $this->plant($orgB, 'B'));

        $response = $this->actingAsApiInOrganization($user, $orgA)
            ->getJson('/api/locations/hierarchy')
            ->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_parametros_invalidos_devuelven_422(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy?plant_id=no-uuid')
            ->assertStatus(422);
        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy?per_page=51')
            ->assertStatus(422);
        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy?status=true')
            ->assertStatus(422);
        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy?q=x')
            ->assertStatus(422);
    }

    public function test_999_nodos_devuelve_200(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        for ($i = 0; $i < 997; $i++) {
            $this->productionLine($area, "L{$i}");
        }

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertOk();
    }

    public function test_1000_nodos_devuelve_200(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        for ($i = 0; $i < 998; $i++) {
            $this->productionLine($area, "L{$i}");
        }

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertOk();
    }

    public function test_una_sola_rama_que_excede_devuelve_422(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        for ($i = 0; $i < 1000; $i++) {
            $this->productionLine($area, "L{$i}");
        }

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/locations/hierarchy')
            ->assertStatus(422);
        $this->assertArrayNotHasKey('data', $response->json() ?? []);
    }
}
