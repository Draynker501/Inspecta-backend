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

class LocationReadsTest extends TestCase
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

    public function test_plant_detail_requires_permission_and_assignment(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $plant = $this->plant($org, 'P');
        app(TenantContext::class)->set($org->id);
        $user->assignPlant($plant);

        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/plants/{$plant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $plant->id);

        $other = $this->plant($org, 'Q');
        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/plants/{$other->id}")
            ->assertNotFound();
    }

    public function test_area_detail_inherited_from_plant(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        app(TenantContext::class)->set($org->id);
        $user->assignPlant($plant);

        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/areas/{$area->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $area->id);
    }

    public function test_line_detail_inherited_from_area(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $line = $this->productionLine($area, 'L');
        app(TenantContext::class)->set($org->id);
        $user->assignArea($area);

        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/production-lines/{$line->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $line->id);
    }

    public function test_restricted_navigation_dec11(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $otherArea = $this->area($plant, 'B');
        app(TenantContext::class)->set($org->id);
        $user->assignArea($area);

        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/plants/{$plant->id}")
            ->assertNotFound();

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/plants/{$plant->id}/areas")
            ->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$area->id], $ids);

        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/areas/{$otherArea->id}")
            ->assertNotFound();
    }

    public function test_empty_filters_return_200(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        app(TenantContext::class)->set($org->id);
        $user->assignArea($area);

        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/plants/{$plant->id}/areas?status=false")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_soft_deleted_ancestors_hide_descendants(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $line = $this->productionLine($area, 'L');
        $plant->delete();

        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/areas/{$area->id}")
            ->assertNotFound();
        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/production-lines/{$line->id}")
            ->assertNotFound();
        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/plants/{$plant->id}/areas")
            ->assertNotFound();
    }

    public function test_inactive_resources_visible(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P', ['status' => false]);
        $area = $this->area($plant, 'A', ['status' => false]);
        $line = $this->productionLine($area, 'L', ['status' => false]);

        $this->actingAsApiInOrganization($user, $org)->getJson("/api/plants/{$plant->id}")->assertOk();
        $this->actingAsApiInOrganization($user, $org)->getJson("/api/areas/{$area->id}")->assertOk();
        $this->actingAsApiInOrganization($user, $org)->getJson("/api/production-lines/{$line->id}")->assertOk();
    }

    public function test_filters_and_like_escape(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P');
        $this->area($plant, 'A_1');
        $this->area($plant, 'A%2');

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/plants/{$plant->id}/areas?q=A_1")
            ->assertOk();
        $this->assertSame(1, $response->json('meta.total'));

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/plants/{$plant->id}/areas?q=A%252")
            ->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_unknown_params_rejected_on_new_endpoints(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value, Permission::LocationsViewAll->value]);
        $plant = $this->plant($org, 'P');

        $this->actingAsApiInOrganization($user, $org)
            ->getJson("/api/plants/{$plant->id}?foo=bar")
            ->assertStatus(422);
    }
}
