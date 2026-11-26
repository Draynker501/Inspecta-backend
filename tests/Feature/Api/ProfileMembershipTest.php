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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ProfileMembershipTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const URL = '/api/auth/me/membership';

    private Organization $organization;

    private User $actor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->clientOrganization(
            'PROFILE-MEMBERSHIP'
        );

        $this->actor = $this->user(
            $this->organization
        );

        $this->actor->refresh();

        $this->token = $this->actor
            ->createToken('profile-membership-test')
            ->plainTextToken;
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Métodos auxiliares
    |--------------------------------------------------------------------------
    */

    private function membership(
        ?Organization $organization = null
    ): TestResponse {
        $organization ??= $this->organization;

        return $this->withToken($this->token)
            ->withHeader(
                'X-Organization-Id',
                $organization->id
            )
            ->getJson(self::URL);
    }

    private function grant(
        User $user,
        Organization $organization,
        array $permissions = [],
        bool $active = true
    ): Role {
        return app(TenantContext::class)->runAs(
            $organization->id,
            function () use (
                $user,
                $organization,
                $permissions,
                $active
            ): Role {
                $role = $this->role(
                    $organization,
                    'Profile-'.Str::random(10)
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

                if (! $active) {
                    $role->status = false;
                    $role->save();
                }

                return $role;
            }
        );
    }

    private function addMembership(
        User $user,
        Organization $organization
    ): OrganizationMembership {
        return OrganizationMembership::firstOrCreate(
            [
                'user_id' => $user->id,
                'organization_id' => $organization->id,
            ],
            [
                'id' => (string) Str::uuid7(),
                'status' => OrganizationMembership::STATUS_ACTIVE,
            ]
        );
    }

    private function emptyLocations(): array
    {
        return [
            'can_view' => false,
            'view_all' => false,
            'plants' => [],
            'areas' => [],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Consulta exitosa de membresía
    |--------------------------------------------------------------------------
    */

    public function test_user_can_view_own_membership(): void
    {
        $membership = OrganizationMembership::findActive(
            $this->actor->id,
            $this->organization->id
        );

        $this->assertNotNull($membership);

        $this->membership()
            ->assertOk()
            ->assertJsonPath(
                'data.organization.id',
                $this->organization->id
            )
            ->assertJsonPath(
                'data.organization.name',
                $this->organization->name
            )
            ->assertJsonPath(
                'data.organization.code',
                $this->organization->code
            )
            ->assertJsonPath(
                'data.organization.type',
                Organization::TYPE_CLIENT
            )
            ->assertJsonPath(
                'data.organization.status',
                true
            )
            ->assertJsonPath(
                'data.membership.id',
                $membership->id
            )
            ->assertJsonPath(
                'data.membership.status',
                OrganizationMembership::STATUS_ACTIVE
            )
            ->assertJsonPath(
                'data.membership.revoked_at',
                null
            );
    }

    public function test_user_without_roles_can_view_membership(): void
    {
        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', [])
            ->assertJsonPath(
                'data.locations',
                $this->emptyLocations()
            );
    }

    public function test_membership_response_has_expected_structure(): void
    {
        $this->membership()
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'organization' => [
                        'id',
                        'name',
                        'code',
                        'type',
                        'status',
                    ],
                    'membership' => [
                        'id',
                        'status',
                        'revoked_at',
                    ],
                    'roles',
                    'permissions',
                    'locations' => [
                        'can_view',
                        'view_all',
                        'plants',
                        'areas',
                    ],
                ],
            ]);
    }

    public function test_membership_response_does_not_expose_sensitive_information(): void
    {
        $membership = OrganizationMembership::findActive(
            $this->actor->id,
            $this->organization->id
        );

        $this->membership()
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'organization' => [
                        'id' => $this->organization->id,
                        'name' => $this->organization->name,
                        'code' => $this->organization->code,
                        'type' => Organization::TYPE_CLIENT,
                        'status' => true,
                    ],
                    'membership' => [
                        'id' => $membership->id,
                        'status' => 'active',
                        'revoked_at' => null,
                    ],
                    'roles' => [],
                    'permissions' => [],
                    'locations' => $this->emptyLocations(),
                ],
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Autenticación
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_user_cannot_view_membership(): void
    {
        $this->withHeader(
            'X-Organization-Id',
            $this->organization->id
        )
            ->getJson(self::URL)
            ->assertUnauthorized();
    }

    public function test_invalid_token_cannot_view_membership(): void
    {
        $this->withToken('invalid-token')
            ->withHeader(
                'X-Organization-Id',
                $this->organization->id
            )
            ->getJson(self::URL)
            ->assertUnauthorized();
    }

    public function test_revoked_token_cannot_view_membership(): void
    {
        PersonalAccessToken::findToken(
            $this->token
        )->delete();

        $this->membership()
            ->assertUnauthorized();
    }

    public function test_expired_token_cannot_view_membership(): void
    {
        $token = PersonalAccessToken::findToken(
            $this->token
        );

        $token->expires_at = now()->subMinute();
        $token->save();

        $this->membership()
            ->assertUnauthorized();
    }

    public function test_inactive_user_cannot_view_membership(): void
    {
        $this->actor->status = false;
        $this->actor->save();

        $this->membership()
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Validación del contexto organizacional
    |--------------------------------------------------------------------------
    */

    public function test_organization_header_is_required(): void
    {
        $this->withToken($this->token)
            ->getJson(self::URL)
            ->assertStatus(400)
            ->assertJsonPath(
                'code',
                'organization_required'
            );
    }

    public function test_invalid_organization_identifier_is_rejected(): void
    {
        $this->withToken($this->token)
            ->withHeader(
                'X-Organization-Id',
                'invalid-organization'
            )
            ->getJson(self::URL)
            ->assertStatus(400)
            ->assertJsonPath(
                'code',
                'organization_invalid'
            );
    }

    public function test_nonexistent_organization_is_rejected(): void
    {
        $this->withToken($this->token)
            ->withHeader(
                'X-Organization-Id',
                (string) Str::uuid7()
            )
            ->getJson(self::URL)
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'organization_access_denied'
            );
    }

    public function test_organization_without_membership_is_rejected(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-FOREIGN'
        );

        $this->membership($otherOrganization)
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'organization_access_denied'
            );
    }

    public function test_inactive_organization_is_rejected(): void
    {
        $this->organization->status = false;
        $this->organization->save();

        $this->membership()
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'organization_access_denied'
            );
    }

    public function test_deleted_organization_is_rejected(): void
    {
        $this->organization->delete();

        $this->membership()
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'organization_access_denied'
            );
    }

    public function test_operator_organization_cannot_be_used_as_tenant(): void
    {
        $operator = $this->operatorOrganization();

        $this->addMembership(
            $this->actor,
            $operator
        );

        $this->membership($operator)
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'organization_not_tenant'
            );
    }

    public function test_legacy_organization_id_does_not_replace_header(): void
    {
        $this->assertSame(
            $this->organization->id,
            $this->actor->organization_id
        );

        $this->withToken($this->token)
            ->getJson(self::URL)
            ->assertStatus(400)
            ->assertJsonPath(
                'code',
                'organization_required'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Estado de membresías
    |--------------------------------------------------------------------------
    */

    public function test_revoked_membership_is_rejected(): void
    {
        $this->actor->memberships()
            ->where(
                'organization_id',
                $this->organization->id
            )
            ->update([
                'status' => OrganizationMembership::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);

        $this->membership()
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'organization_access_denied'
            );
    }

    public function test_deleted_membership_is_rejected(): void
    {
        $this->actor->memberships()
            ->where(
                'organization_id',
                $this->organization->id
            )
            ->delete();

        $this->membership()
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'organization_access_denied'
            );
    }

    public function test_revoked_membership_does_not_block_another_active_membership(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-ACTIVE'
        );

        $otherMembership = $this->addMembership(
            $this->actor,
            $otherOrganization
        );

        $this->actor->memberships()
            ->where(
                'organization_id',
                $this->organization->id
            )
            ->update([
                'status' => OrganizationMembership::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);

        $this->membership()
            ->assertForbidden();

        $this->membership($otherOrganization)
            ->assertOk()
            ->assertJsonPath(
                'data.membership.id',
                $otherMembership->id
            );
    }

    public function test_user_can_view_membership_in_secondary_organization(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-SECONDARY'
        );

        $membership = $this->addMembership(
            $this->actor,
            $otherOrganization
        );

        $this->membership($otherOrganization)
            ->assertOk()
            ->assertJsonPath(
                'data.organization.id',
                $otherOrganization->id
            )
            ->assertJsonPath(
                'data.membership.id',
                $membership->id
            );

        $this->assertSame(
            $this->organization->id,
            $this->actor->fresh()->organization_id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Roles y permisos efectivos
    |--------------------------------------------------------------------------
    */

    public function test_active_assigned_role_is_returned(): void
    {
        $role = $this->grant(
            $this->actor,
            $this->organization,
            [Permission::ChecklistsView]
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.roles', [
                [
                    'id' => $role->id,
                    'name' => $role->name,
                    'status' => true,
                ],
            ])
            ->assertJsonPath('data.permissions', [
                Permission::ChecklistsView->value,
            ]);
    }

    public function test_inactive_role_is_returned_without_effective_permissions(): void
    {
        $role = $this->grant(
            $this->actor,
            $this->organization,
            [Permission::UsersManage],
            false
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.roles', [
                [
                    'id' => $role->id,
                    'name' => $role->name,
                    'status' => false,
                ],
            ])
            ->assertJsonPath('data.permissions', []);
    }

    public function test_permissions_are_combined_from_active_roles(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [
                Permission::ChecklistsView,
                Permission::NormsView,
            ]
        );

        $this->grant(
            $this->actor,
            $this->organization,
            [
                Permission::ReportsViewOwn,
                Permission::InspectionsExecute,
            ]
        );

        $this->membership()
            ->assertOk()
            ->assertJsonCount(2, 'data.roles')
            ->assertJsonPath('data.permissions', [
                Permission::ChecklistsView->value,
                Permission::InspectionsExecute->value,
                Permission::NormsView->value,
                Permission::ReportsViewOwn->value,
            ]);
    }

    public function test_duplicate_permissions_are_returned_only_once(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::ChecklistsView]
        );

        $this->grant(
            $this->actor,
            $this->organization,
            [
                Permission::ChecklistsView,
                Permission::NormsView,
            ]
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.permissions', [
                Permission::ChecklistsView->value,
                Permission::NormsView->value,
            ]);
    }

    public function test_inactive_roles_do_not_grant_additional_permissions(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::ChecklistsView]
        );

        $this->grant(
            $this->actor,
            $this->organization,
            [
                Permission::UsersManage,
                Permission::RolesManage,
                Permission::PlantsManage,
            ],
            false
        );

        $this->membership()
            ->assertOk()
            ->assertJsonCount(2, 'data.roles')
            ->assertJsonPath('data.permissions', [
                Permission::ChecklistsView->value,
            ]);
    }

    public function test_user_can_view_all_effective_permissions(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            Permission::cases()
        );

        $expected = array_map(
            fn (Permission $permission): string => $permission->value,
            Permission::cases()
        );

        sort($expected);

        $this->membership()
            ->assertOk()
            ->assertJsonPath(
                'data.permissions',
                $expected
            );
    }

    public function test_unassigned_roles_are_not_returned(): void
    {
        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $this->role(
                    $this->organization,
                    'Unassigned-Profile-Role'
                );
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', []);
    }

    public function test_roles_assigned_to_another_user_are_not_returned(): void
    {
        $otherUser = $this->user(
            $this->organization
        );

        $this->grant(
            $otherUser,
            $this->organization,
            [Permission::UsersManage]
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', []);
    }

    /*
    |--------------------------------------------------------------------------
    | Permisos de consulta de ubicaciones
    |--------------------------------------------------------------------------
    */

    public function test_locations_are_hidden_without_plants_view(): void
    {
        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $plant = $this->plant(
                    $this->organization,
                    'HIDDEN-PLANT'
                );

                $area = $this->area(
                    $plant,
                    'HIDDEN-AREA'
                );

                $this->actor->assignPlant($plant);
                $this->actor->assignArea($area);
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath(
                'data.locations',
                $this->emptyLocations()
            );
    }

    public function test_plants_view_enables_location_information(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.locations', [
                'can_view' => true,
                'view_all' => false,
                'plants' => [],
                'areas' => [],
            ]);
    }

    public function test_locations_view_all_requires_plants_view(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::LocationsViewAll]
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath(
                'data.locations',
                $this->emptyLocations()
            );
    }

    public function test_locations_view_all_is_reported_with_plants_view(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [
                Permission::PlantsView,
                Permission::LocationsViewAll,
            ]
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath(
                'data.locations.can_view',
                true
            )
            ->assertJsonPath(
                'data.locations.view_all',
                true
            );
    }

    public function test_inactive_role_does_not_grant_location_visibility(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [
                Permission::PlantsView,
                Permission::LocationsViewAll,
            ],
            false
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath(
                'data.locations',
                $this->emptyLocations()
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Plantas y áreas asignadas
    |--------------------------------------------------------------------------
    */

    public function test_directly_assigned_plant_is_returned(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        $plant = app(TenantContext::class)->runAs(
            $this->organization->id,
            function () {
                $plant = $this->plant(
                    $this->organization,
                    'ASSIGNED-PLANT'
                );

                $this->actor->assignPlant($plant);

                return $plant;
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.locations.plants', [
                [
                    'id' => $plant->id,
                    'name' => $plant->name,
                    'code' => $plant->code,
                    'status' => true,
                    'available' => true,
                ],
            ]);
    }

    public function test_directly_assigned_area_is_returned(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        [$plant, $area] = app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): array {
                $plant = $this->plant(
                    $this->organization,
                    'AREA-PARENT'
                );

                $area = $this->area(
                    $plant,
                    'ASSIGNED-AREA'
                );

                $this->actor->assignArea($area);

                return [$plant, $area];
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.locations.plants', [])
            ->assertJsonPath('data.locations.areas', [
                [
                    'id' => $area->id,
                    'plant_id' => $plant->id,
                    'plant_name' => $plant->name,
                    'name' => $area->name,
                    'code' => $area->code,
                    'status' => true,
                    'available' => true,
                ],
            ]);
    }

    public function test_unassigned_plants_and_areas_are_not_returned(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $plant = $this->plant(
                    $this->organization,
                    'UNASSIGNED-PLANT'
                );

                $this->area(
                    $plant,
                    'UNASSIGNED-AREA'
                );
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.locations.plants', [])
            ->assertJsonPath('data.locations.areas', []);
    }

    public function test_plant_assignment_does_not_create_direct_area_assignments(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $plant = $this->plant(
                    $this->organization,
                    'DIRECT-PLANT'
                );

                $this->area(
                    $plant,
                    'INDIRECT-AREA'
                );

                $this->actor->assignPlant($plant);
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonCount(1, 'data.locations.plants')
            ->assertJsonPath('data.locations.areas', []);
    }

    public function test_view_all_does_not_return_unassigned_locations(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [
                Permission::PlantsView,
                Permission::LocationsViewAll,
            ]
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $assignedPlant = $this->plant(
                    $this->organization,
                    'VIEW-ALL-ASSIGNED'
                );

                $unassignedPlant = $this->plant(
                    $this->organization,
                    'VIEW-ALL-UNASSIGNED'
                );

                $this->area(
                    $unassignedPlant,
                    'VIEW-ALL-AREA'
                );

                $this->actor->assignPlant(
                    $assignedPlant
                );
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath(
                'data.locations.view_all',
                true
            )
            ->assertJsonCount(1, 'data.locations.plants')
            ->assertJsonPath('data.locations.areas', []);
    }

    public function test_locations_assigned_to_another_user_are_not_returned(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        $otherUser = $this->user(
            $this->organization
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function () use ($otherUser): void {
                $plant = $this->plant(
                    $this->organization,
                    'OTHER-USER-PLANT'
                );

                $area = $this->area(
                    $plant,
                    'OTHER-USER-AREA'
                );

                $otherUser->assignPlant($plant);
                $otherUser->assignArea($area);
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.locations.plants', [])
            ->assertJsonPath('data.locations.areas', []);
    }

    /*
    |--------------------------------------------------------------------------
    | Disponibilidad de ubicaciones
    |--------------------------------------------------------------------------
    */

    public function test_inactive_assigned_plant_is_marked_unavailable(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $plant = $this->plant(
                    $this->organization,
                    'INACTIVE-PLANT'
                );

                $this->actor->assignPlant($plant);

                $plant->status = false;
                $plant->save();
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonCount(1, 'data.locations.plants')
            ->assertJsonPath(
                'data.locations.plants.0.status',
                false
            )
            ->assertJsonPath(
                'data.locations.plants.0.available',
                false
            );
    }

    public function test_inactive_assigned_area_is_marked_unavailable(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $plant = $this->plant(
                    $this->organization,
                    'ACTIVE-PARENT'
                );

                $area = $this->area(
                    $plant,
                    'INACTIVE-AREA'
                );

                $this->actor->assignArea($area);

                $area->status = false;
                $area->save();
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonCount(1, 'data.locations.areas')
            ->assertJsonPath(
                'data.locations.areas.0.status',
                false
            )
            ->assertJsonPath(
                'data.locations.areas.0.available',
                false
            );
    }

    public function test_active_area_under_inactive_plant_is_unavailable(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $plant = $this->plant(
                    $this->organization,
                    'INACTIVE-PARENT'
                );

                $area = $this->area(
                    $plant,
                    'ACTIVE-CHILD'
                );

                $this->actor->assignArea($area);

                $plant->status = false;
                $plant->save();
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonCount(1, 'data.locations.areas')
            ->assertJsonPath(
                'data.locations.areas.0.status',
                true
            )
            ->assertJsonPath(
                'data.locations.areas.0.available',
                false
            );
    }

    public function test_soft_deleted_plant_is_not_returned(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $plant = $this->plant(
                    $this->organization,
                    'DELETED-PLANT'
                );

                $this->actor->assignPlant($plant);

                $plant->delete();
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.locations.plants', []);
    }

    public function test_soft_deleted_area_is_not_returned(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $plant = $this->plant(
                    $this->organization,
                    'DELETED-AREA-PARENT'
                );

                $area = $this->area(
                    $plant,
                    'DELETED-AREA'
                );

                $this->actor->assignArea($area);

                $area->delete();
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.locations.areas', []);
    }

    public function test_area_under_soft_deleted_plant_is_not_returned(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $plant = $this->plant(
                    $this->organization,
                    'DELETED-PARENT'
                );

                $area = $this->area(
                    $plant,
                    'ACTIVE-AREA-DELETED-PARENT'
                );

                $this->actor->assignArea($area);

                $plant->delete();
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.locations.areas', []);
    }

    /*
    |--------------------------------------------------------------------------
    | Aislamiento multi-tenancy
    |--------------------------------------------------------------------------
    */

    public function test_roles_are_isolated_between_organizations(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-ROLE-ISOLATION'
        );

        $this->addMembership(
            $this->actor,
            $otherOrganization
        );

        $firstRole = $this->grant(
            $this->actor,
            $this->organization,
            [Permission::ChecklistsView]
        );

        $secondRole = $this->grant(
            $this->actor,
            $otherOrganization,
            [Permission::UsersManage]
        );

        $this->membership()
            ->assertOk()
            ->assertJsonCount(1, 'data.roles')
            ->assertJsonPath(
                'data.roles.0.id',
                $firstRole->id
            );

        $this->membership($otherOrganization)
            ->assertOk()
            ->assertJsonCount(1, 'data.roles')
            ->assertJsonPath(
                'data.roles.0.id',
                $secondRole->id
            );
    }

    public function test_permissions_are_isolated_between_organizations(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-PERM-ISOLATION'
        );

        $this->addMembership(
            $this->actor,
            $otherOrganization
        );

        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::InspectionsExecute]
        );

        $this->grant(
            $this->actor,
            $otherOrganization,
            [
                Permission::UsersManage,
                Permission::RolesManage,
            ]
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.permissions', [
                Permission::InspectionsExecute->value,
            ]);

        $this->membership($otherOrganization)
            ->assertOk()
            ->assertJsonPath('data.permissions', [
                Permission::RolesManage->value,
                Permission::UsersManage->value,
            ]);

        $this->membership()
            ->assertOk()
            ->assertJsonPath('data.permissions', [
                Permission::InspectionsExecute->value,
            ]);
    }

    public function test_plants_are_isolated_between_organizations(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-PLANT-ISOLATION'
        );

        $this->addMembership(
            $this->actor,
            $otherOrganization
        );

        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        $this->grant(
            $this->actor,
            $otherOrganization,
            [Permission::PlantsView]
        );

        $firstPlant = app(TenantContext::class)->runAs(
            $this->organization->id,
            function () {
                $plant = $this->plant(
                    $this->organization,
                    'FIRST-TENANT-PLANT'
                );

                $this->actor->assignPlant($plant);

                return $plant;
            }
        );

        $secondPlant = app(TenantContext::class)->runAs(
            $otherOrganization->id,
            function () use ($otherOrganization) {
                $plant = $this->plant(
                    $otherOrganization,
                    'SECOND-TENANT-PLANT'
                );

                $this->actor->assignPlant($plant);

                return $plant;
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonCount(1, 'data.locations.plants')
            ->assertJsonPath(
                'data.locations.plants.0.id',
                $firstPlant->id
            );

        $this->membership($otherOrganization)
            ->assertOk()
            ->assertJsonCount(1, 'data.locations.plants')
            ->assertJsonPath(
                'data.locations.plants.0.id',
                $secondPlant->id
            );
    }

    public function test_areas_are_isolated_between_organizations(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-AREA-ISOLATION'
        );

        $this->addMembership(
            $this->actor,
            $otherOrganization
        );

        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        $this->grant(
            $this->actor,
            $otherOrganization,
            [Permission::PlantsView]
        );

        $firstArea = app(TenantContext::class)->runAs(
            $this->organization->id,
            function () {
                $plant = $this->plant(
                    $this->organization,
                    'FIRST-AREA-PARENT'
                );

                $area = $this->area(
                    $plant,
                    'FIRST-TENANT-AREA'
                );

                $this->actor->assignArea($area);

                return $area;
            }
        );

        $secondArea = app(TenantContext::class)->runAs(
            $otherOrganization->id,
            function () use ($otherOrganization) {
                $plant = $this->plant(
                    $otherOrganization,
                    'SECOND-AREA-PARENT'
                );

                $area = $this->area(
                    $plant,
                    'SECOND-TENANT-AREA'
                );

                $this->actor->assignArea($area);

                return $area;
            }
        );

        $this->membership()
            ->assertOk()
            ->assertJsonCount(1, 'data.locations.areas')
            ->assertJsonPath(
                'data.locations.areas.0.id',
                $firstArea->id
            );

        $this->membership($otherOrganization)
            ->assertOk()
            ->assertJsonCount(1, 'data.locations.areas')
            ->assertJsonPath(
                'data.locations.areas.0.id',
                $secondArea->id
            );
    }

    public function test_location_visibility_is_isolated_between_organizations(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-VIEW-ISOLATION'
        );

        $this->addMembership(
            $this->actor,
            $otherOrganization
        );

        $this->grant(
            $this->actor,
            $this->organization,
            [
                Permission::PlantsView,
                Permission::LocationsViewAll,
            ]
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath(
                'data.locations.can_view',
                true
            )
            ->assertJsonPath(
                'data.locations.view_all',
                true
            );

        $this->membership($otherOrganization)
            ->assertOk()
            ->assertJsonPath(
                'data.locations',
                $this->emptyLocations()
            );
    }

    public function test_switching_organizations_returns_correct_membership(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-SWITCH'
        );

        $firstMembership = OrganizationMembership::findActive(
            $this->actor->id,
            $this->organization->id
        );

        $secondMembership = $this->addMembership(
            $this->actor,
            $otherOrganization
        );

        $this->membership()
            ->assertOk()
            ->assertJsonPath(
                'data.membership.id',
                $firstMembership->id
            );

        $this->membership($otherOrganization)
            ->assertOk()
            ->assertJsonPath(
                'data.membership.id',
                $secondMembership->id
            );

        $this->membership()
            ->assertOk()
            ->assertJsonPath(
                'data.membership.id',
                $firstMembership->id
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Protección de identidad e integridad
    |--------------------------------------------------------------------------
    */

    public function test_query_user_id_cannot_select_another_membership(): void
    {
        $otherUser = $this->user(
            $this->organization
        );

        $ownMembership = OrganizationMembership::findActive(
            $this->actor->id,
            $this->organization->id
        );

        $this->withToken($this->token)
            ->withHeader(
                'X-Organization-Id',
                $this->organization->id
            )
            ->getJson(
                self::URL.'?user_id='.$otherUser->id
            )
            ->assertOk()
            ->assertJsonPath(
                'data.membership.id',
                $ownMembership->id
            );
    }

    public function test_query_organization_id_cannot_override_header(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-QUERY-ORG'
        );

        $this->addMembership(
            $this->actor,
            $otherOrganization
        );

        $this->withToken($this->token)
            ->withHeader(
                'X-Organization-Id',
                $this->organization->id
            )
            ->getJson(
                self::URL.'?organization_id='.$otherOrganization->id
            )
            ->assertOk()
            ->assertJsonPath(
                'data.organization.id',
                $this->organization->id
            );
    }

    public function test_membership_query_does_not_modify_user_or_membership(): void
    {
        $membership = OrganizationMembership::findActive(
            $this->actor->id,
            $this->organization->id
        );

        $originalUser = $this->actor
            ->fresh()
            ->getAttributes();

        $originalMembership = $membership
            ->fresh()
            ->getAttributes();

        $this->membership()
            ->assertOk();

        $this->assertSame(
            $originalUser,
            $this->actor->fresh()->getAttributes()
        );

        $this->assertSame(
            $originalMembership,
            $membership->fresh()->getAttributes()
        );
    }

    public function test_membership_query_does_not_modify_assignments(): void
    {
        $this->grant(
            $this->actor,
            $this->organization,
            [Permission::PlantsView]
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $plant = $this->plant(
                    $this->organization,
                    'READ-ONLY-PLANT'
                );

                $area = $this->area(
                    $plant,
                    'READ-ONLY-AREA'
                );

                $this->actor->assignPlant($plant);
                $this->actor->assignArea($area);
            }
        );

        $tables = [
            'user_roles',
            'user_plants',
            'user_areas',
        ];

        $before = [];

        foreach ($tables as $table) {
            $before[$table] = DB::table($table)
                ->where('user_id', $this->actor->id)
                ->where(
                    'organization_id',
                    $this->organization->id
                )
                ->get()
                ->toJson();
        }

        $this->membership()
            ->assertOk();

        foreach ($tables as $table) {
            $after = DB::table($table)
                ->where('user_id', $this->actor->id)
                ->where(
                    'organization_id',
                    $this->organization->id
                )
                ->get()
                ->toJson();

            $this->assertSame(
                $before[$table],
                $after
            );
        }
    }

    public function test_membership_query_preserves_current_token(): void
    {
        $this->membership()
            ->assertOk();

        $this->assertNotNull(
            PersonalAccessToken::findToken(
                $this->token
            )
        );

        $this->withToken($this->token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $this->actor->id
            );
    }
}
