<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plant;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class LocationScopePolicyTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function grant(User $user, Organization $org, string $roleName, array $permissions): Role
    {
        $role = $this->role($org, $roleName);

        app(TenantContext::class)->set($org->id);
        $user->assignRole($role);

        foreach ($permissions as $permission) {
            RolePermission::create([
                'role_id' => $role->id,
                'organization_id' => $org->id,
                'permission' => $permission,
            ]);
        }

        return $role;
    }

    private function makeTree(Organization $org): array
    {
        $plant = $this->plant($org, 'P1');
        $area = $this->area($plant, 'A1');
        $line = $this->productionLine($area, 'L1');

        $otherPlant = $this->plant($org, 'P2');
        $otherArea = $this->area($otherPlant, 'A2');

        return [$plant, $area, $line, $otherPlant, $otherArea];
    }

    public function test_admin_con_locations_view_all_puede_leer_todo(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);

        [$plant, $area, $line, $otherPlant, $otherArea] = $this->makeTree($org);

        app(TenantContext::class)->set($org->id);

        $this->assertTrue($user->can('view', $plant));
        $this->assertTrue($user->can('view', $area));
        $this->assertTrue($user->can('view', $line));
        $this->assertTrue($user->can('view', $otherPlant));
        $this->assertTrue($user->can('viewAny', Plant::class));
    }

    public function test_supervisor_con_locations_view_all_puede_leer_todo(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Supervisor', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);

        [$plant, $area, $line] = $this->makeTree($org);

        app(TenantContext::class)->set($org->id);

        $this->assertTrue($user->can('view', $plant));
        $this->assertTrue($user->can('view', $area));
        $this->assertTrue($user->can('view', $line));
    }

    public function test_inspector_sin_locations_view_all_solo_asignados(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);

        [$plant, $area, $line, $otherPlant] = $this->makeTree($org);

        app(TenantContext::class)->set($org->id);
        $user->assignPlant($plant);

        $this->assertTrue($user->can('view', $plant));
        $this->assertFalse($user->can('view', $otherPlant));
    }

    public function test_locations_view_all_sin_plants_view_deniega(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::LocationsViewAll->value]);

        [$plant] = $this->makeTree($org);

        app(TenantContext::class)->set($org->id);

        $this->assertFalse($user->can('view', $plant));
        $this->assertFalse($user->can('viewAny', Plant::class));
    }

    public function test_inspector_con_planta_asignada_hereda_areas_y_lineas(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);

        [$plant, $area, $line] = $this->makeTree($org);

        app(TenantContext::class)->set($org->id);
        $user->assignPlant($plant);

        $this->assertTrue($user->can('view', $plant));
        $this->assertTrue($user->can('view', $area));
        $this->assertTrue($user->can('view', $line));
    }

    public function test_inspector_con_area_asignada_hereda_lineas_pero_no_planta(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);

        [$plant, $area, $line, $otherPlant, $otherArea] = $this->makeTree($org);

        app(TenantContext::class)->set($org->id);
        $user->assignArea($area);

        $this->assertTrue($user->can('view', $area));
        $this->assertTrue($user->can('view', $line));
        $this->assertFalse($user->can('view', $plant));
        $this->assertFalse($user->can('view', $otherArea));
    }

    public function test_herencia_planta_no_concede_areas_hermanas(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);

        $plant = $this->plant($org, 'P1');
        $area1 = $this->area($plant, 'A1');
        $area2 = $this->area($plant, 'A2');

        app(TenantContext::class)->set($org->id);
        $user->assignPlant($plant);

        $this->assertTrue($user->can('view', $area1));
        $this->assertTrue($user->can('view', $area2));
    }

    public function test_planta_no_asignada_denegada(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);

        $plant = $this->plant($org, 'P1');

        app(TenantContext::class)->set($org->id);

        $this->assertFalse($user->can('view', $plant));
    }

    public function test_recurso_de_otra_organizacion_denegado(): void
    {
        $orgA = $this->clientOrganization('ORG-A');
        $orgB = $this->clientOrganization('ORG-B');

        $user = $this->user($orgA);
        $this->grant($user, $orgA, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);

        $plantB = app(TenantContext::class)->runAs($orgB->id, fn () => $this->plant($orgB, 'PB'));

        app(TenantContext::class)->set($orgA->id);

        $this->assertFalse($user->can('view', $plantB));
    }

    public function test_cambio_de_organizacion_activa(): void
    {
        $orgA = $this->clientOrganization('ORG-A');
        $orgB = $this->clientOrganization('ORG-B');

        $user = $this->user($orgA);
        OrganizationMembership::firstOrCreate(
            ['user_id' => $user->id, 'organization_id' => $orgB->id],
            ['id' => (string) Str::uuid7(), 'status' => OrganizationMembership::STATUS_ACTIVE],
        );

        $this->grant($user, $orgA, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);

        $plantA = $this->plant($orgA, 'PA');

        app(TenantContext::class)->set($orgA->id);
        $this->assertTrue($user->can('view', $plantA));

        app(TenantContext::class)->set($orgB->id);
        $this->assertFalse($user->can('view', $plantA));
    }

    public function test_membresia_revocada_deniega(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);

        $plant = $this->plant($org, 'P1');

        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $org->id)
            ->first();
        $membership->status = OrganizationMembership::STATUS_REVOKED;
        $membership->save();

        app(TenantContext::class)->set($org->id);

        $this->assertFalse($user->can('view', $plant));
    }

    public function test_permiso_o_asignacion_eliminada_entre_llamadas(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $role = $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);

        $plant = $this->plant($org, 'P1');

        app(TenantContext::class)->set($org->id);
        $user->assignPlant($plant);

        app(TenantContext::class)->clear();
        app(TenantContext::class)->set($org->id);
        $this->assertTrue($user->can('view', $plant));

        DB::table('user_plants')
            ->where('user_id', $user->id)
            ->where('plant_id', $plant->id)
            ->delete();

        app(TenantContext::class)->clear();
        app(TenantContext::class)->set($org->id);
        $this->assertFalse($user->can('view', $plant));
    }

    public function test_viewany_view_create_update_delete(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Administrador', [
            Permission::PlantsView->value,
            Permission::PlantsManage->value,
            Permission::LocationsViewAll->value,
        ]);

        $plant = $this->plant($org, 'P1');

        app(TenantContext::class)->set($org->id);

        $this->assertTrue($user->can('viewAny', Plant::class));
        $this->assertTrue($user->can('view', $plant));
        $this->assertTrue($user->can('create', Plant::class));
        $this->assertTrue($user->can('update', $plant));
        $this->assertTrue($user->can('delete', $plant));
    }

    public function test_locations_view_all_no_concede_escritura(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);

        $plant = $this->plant($org, 'P1');

        app(TenantContext::class)->set($org->id);

        $this->assertTrue($user->can('view', $plant));
        $this->assertFalse($user->can('create', Plant::class));
        $this->assertFalse($user->can('update', $plant));
        $this->assertFalse($user->can('delete', $plant));
    }
}
