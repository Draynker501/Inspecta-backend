<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class AdministrationCapabilitiesTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function grant(
        User $user,
        Organization $organization,
        array $permissions
    ): Role {
        return app(TenantContext::class)->runAs(
            $organization->id,
            function () use ($user, $organization, $permissions) {
                $role = $this->role(
                    $organization,
                    'Admin-Cap-'.Str::random(10)
                );

                foreach ($permissions as $permission) {
                    RolePermission::create([
                        'id' => (string) Str::uuid7(),
                        'role_id' => $role->id,
                        'organization_id' => $organization->id,
                        'permission' => $permission->value,
                    ]);
                }

                $user->assignRole($role);

                return $role;
            }
        );
    }

    private function capabilities(
        User $user,
        Organization $organization
    ) {
        return $this->actingAsApiInOrganization(
            $user,
            $organization
        )->getJson('/api/auth/capabilities');
    }

    private function emptyCapabilities(): array
    {
        return [
            'manage_users' => false,
            'manage_memberships' => false,
            'manage_roles' => false,
            'view_locations' => false,
            'manage_locations' => false,
            'view_all_locations' => false,
            'create_users' => false,
            'assign_roles' => false,
            'assign_locations' => false,
        ];
    }

    public function test_user_without_permissions_has_no_administrative_capabilities(): void
    {
        $organization = $this->clientOrganization(
            'ADMIN-CAP-EMPTY'
        );

        $user = $this->user($organization);

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath(
                'modules.administration',
                false
            )
            ->assertJsonPath(
                'administration',
                $this->emptyCapabilities()
            );
    }

    public function test_all_administrative_capabilities_are_enabled(): void
    {
        $organization = $this->clientOrganization(
            'ADMIN-CAP-ALL'
        );

        $user = $this->user($organization);

        $this->grant(
            $user,
            $organization,
            Permission::cases()
        );

        $expected = array_fill_keys(
            array_keys($this->emptyCapabilities()),
            true
        );

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath(
                'modules.administration',
                true
            )
            ->assertJsonPath(
                'administration',
                $expected
            );
    }

    public function test_users_manage_does_not_enable_role_or_location_assignment(): void
    {
        $organization = $this->clientOrganization(
            'ADMIN-CAP-USERS'
        );

        $user = $this->user($organization);

        $this->grant(
            $user,
            $organization,
            [Permission::UsersManage]
        );

        $expected = $this->emptyCapabilities();

        $expected['manage_users'] = true;

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath(
                'modules.administration',
                true
            )
            ->assertJsonPath(
                'administration',
                $expected
            );
    }

    public function test_user_creation_requires_users_and_memberships_manage(): void
    {
        $organization = $this->clientOrganization(
            'ADMIN-CAP-CREATE'
        );

        $user = $this->user($organization);

        $this->grant(
            $user,
            $organization,
            [Permission::MembershipsManage]
        );

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath(
                'administration.manage_memberships',
                true
            )
            ->assertJsonPath(
                'administration.create_users',
                false
            );

        $this->grant(
            $user,
            $organization,
            [Permission::UsersManage]
        );

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath(
                'administration.create_users',
                true
            );
    }

    public function test_role_assignment_requires_users_and_roles_manage(): void
    {
        $organization = $this->clientOrganization(
            'ADMIN-CAP-ROLES'
        );

        $user = $this->user($organization);

        $this->grant(
            $user,
            $organization,
            [Permission::RolesManage]
        );

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath(
                'administration.manage_roles',
                true
            )
            ->assertJsonPath(
                'administration.assign_roles',
                false
            );

        $this->grant(
            $user,
            $organization,
            [Permission::UsersManage]
        );

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath(
                'administration.assign_roles',
                true
            );
    }

    public function test_location_assignment_requires_users_manage_plants_manage_and_plants_view(): void
    {
        $organization = $this->clientOrganization(
            'ADMIN-CAP-LOCATIONS'
        );

        $user = $this->user($organization);

        // Primer permiso: administración de ubicaciones.
        $this->grant(
            $user,
            $organization,
            [Permission::PlantsManage]
        );

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath(
                'administration.manage_locations',
                true
            )
            ->assertJsonPath(
                'administration.view_locations',
                false
            )
            ->assertJsonPath(
                'administration.assign_locations',
                false
            );

        // Segundo permiso: administración de usuarios.
        $this->grant(
            $user,
            $organization,
            [Permission::UsersManage]
        );

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath(
                'administration.manage_users',
                true
            )
            ->assertJsonPath(
                'administration.manage_locations',
                true
            )
            ->assertJsonPath(
                'administration.view_locations',
                false
            )
            ->assertJsonPath(
                'administration.assign_locations',
                false
            );

        // Tercer permiso: consulta de ubicaciones.
        $this->grant(
            $user,
            $organization,
            [Permission::PlantsView]
        );

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath(
                'administration.manage_users',
                true
            )
            ->assertJsonPath(
                'administration.manage_locations',
                true
            )
            ->assertJsonPath(
                'administration.view_locations',
                true
            )
            ->assertJsonPath(
                'administration.assign_locations',
                true
            );
    }

    public function test_location_read_permission_does_not_enable_administration_module(): void
    {
        $organization = $this->clientOrganization(
            'ADMIN-CAP-READ'
        );

        $user = $this->user($organization);

        $this->grant(
            $user,
            $organization,
            [
                Permission::PlantsView,
                Permission::LocationsViewAll,
            ]
        );

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath(
                'modules.administration',
                false
            )
            ->assertJsonPath(
                'administration.view_locations',
                true
            )
            ->assertJsonPath(
                'administration.view_all_locations',
                true
            )
            ->assertJsonPath(
                'administration.manage_locations',
                false
            );
    }

    public function test_inactive_role_does_not_enable_administration(): void
    {
        $organization = $this->clientOrganization(
            'ADMIN-CAP-INACTIVE'
        );

        $user = $this->user($organization);

        $role = $this->grant(
            $user,
            $organization,
            Permission::cases()
        );

        app(TenantContext::class)->runAs(
            $organization->id,
            fn () => $role->update([
                'status' => false,
            ])
        );

        $this->capabilities($user, $organization)
            ->assertOk()
            ->assertJsonPath('roles', [])
            ->assertJsonPath(
                'modules.administration',
                false
            )
            ->assertJsonPath(
                'administration',
                $this->emptyCapabilities()
            );
    }

    public function test_administrative_capabilities_are_isolated_by_organization(): void
    {
        $organizationA = $this->clientOrganization(
            'ADMIN-CAP-ORG-A'
        );

        $organizationB = $this->clientOrganization(
            'ADMIN-CAP-ORG-B'
        );

        $user = $this->user($organizationA);

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $organizationB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->grant(
            $user,
            $organizationA,
            [
                Permission::UsersManage,
                Permission::RolesManage,
            ]
        );

        $this->grant(
            $user,
            $organizationB,
            [Permission::PlantsView]
        );

        $this->capabilities($user, $organizationA)
            ->assertOk()
            ->assertJsonPath(
                'organization_id',
                $organizationA->id
            )
            ->assertJsonPath(
                'administration.assign_roles',
                true
            )
            ->assertJsonPath(
                'modules.administration',
                true
            );

        $this->capabilities($user, $organizationB)
            ->assertOk()
            ->assertJsonPath(
                'organization_id',
                $organizationB->id
            )
            ->assertJsonPath(
                'administration.assign_roles',
                false
            )
            ->assertJsonPath(
                'administration.view_locations',
                true
            )
            ->assertJsonPath(
                'modules.administration',
                false
            );
    }

    public function test_foreign_organization_does_not_expose_administrative_capabilities(): void
    {
        $organizationA = $this->clientOrganization(
            'ADMIN-CAP-OWN'
        );

        $organizationB = $this->clientOrganization(
            'ADMIN-CAP-FOREIGN'
        );

        $user = $this->user($organizationA);

        $this->capabilities($user, $organizationB)
            ->assertForbidden()
            ->assertJsonMissingPath('administration');
    }
}
