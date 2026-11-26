<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plant;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class AdministrationUserLocationsTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private Organization $organization;

    private User $administrator;

    private User $target;

    private Plant $plantA;

    private Plant $plantB;

    private Area $areaA;

    private Area $areaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->clientOrganization(
            'ADMIN-USER-LOCATIONS'
        );

        $this->administrator = $this->createMember(
            Permission::cases(),
            'admin'
        );

        $this->target = $this->user(
            $this->organization
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $this->plantA = $this->plant(
                    $this->organization,
                    'PLANT-A'
                );

                $this->plantB = $this->plant(
                    $this->organization,
                    'PLANT-B'
                );

                $this->areaA = $this->area(
                    $this->plantA,
                    'AREA-A'
                );

                $this->areaB = $this->area(
                    $this->plantB,
                    'AREA-B'
                );
            }
        );
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures auxiliares
    |--------------------------------------------------------------------------
    */

    private function createMember(
        array $permissions = [],
        ?string $systemKey = null
    ): User {
        $user = $this->user(
            $this->organization
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function () use (
                $user,
                $permissions,
                $systemKey
            ): void {
                $role = $this->role(
                    $this->organization,
                    'Location-Role-'.Str::random(10)
                );

                foreach ($permissions as $permission) {
                    RolePermission::create([
                        'id' => (string) Str::uuid7(),
                        'organization_id' => $this->organization->id,
                        'role_id' => $role->id,
                        'permission' => $permission->value,
                    ]);
                }

                if ($systemKey !== null) {
                    DB::table('roles')
                        ->where('id', $role->id)
                        ->update([
                            'system_key' => $systemKey,
                        ]);

                    $role->refresh();
                }

                $user->assignRole($role);
            }
        );

        return $user;
    }

    private function asAdministrator(): static
    {
        return $this->actingAsApiInOrganization(
            $this->administrator,
            $this->organization
        );
    }

    private function asMember(User $user): static
    {
        return $this->actingAsApiInOrganization(
            $user,
            $this->organization
        );
    }

    private function url(?User $user = null): string
    {
        return '/api/users/'
            .($user ?? $this->target)->id
            .'/locations';
    }

    private function payload(
        array $plantIds = [],
        array $areaIds = []
    ): array {
        return [
            'plant_ids' => $plantIds,
            'area_ids' => $areaIds,
        ];
    }

    private function assignPlantTo(
        User $user,
        Plant $plant
    ): void {
        app(TenantContext::class)->runAs(
            $this->organization->id,
            fn () => $user->assignPlant($plant)
        );
    }

    private function assignAreaTo(
        User $user,
        Area $area
    ): void {
        app(TenantContext::class)->runAs(
            $this->organization->id,
            fn () => $user->assignArea($area)
        );
    }

    private function locationPermissions(): array
    {
        return [
            Permission::UsersManage,
            Permission::PlantsManage,
            Permission::PlantsView,
        ];
    }

    private function auditCount(): int
    {
        return DB::table('audit_logs')
            ->where(
                'organization_id',
                $this->organization->id
            )
            ->where(
                'action',
                'user.locations_updated'
            )
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Operaciones funcionales
    |--------------------------------------------------------------------------
    */

    public function test_assigns_plant_to_member(): void
    {
        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantA->id]
                )
            )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $this->target->id
            )
            ->assertJsonCount(
                1,
                'data.locations.plants'
            )
            ->assertJsonPath(
                'data.locations.plants.0.id',
                $this->plantA->id
            )
            ->assertJsonPath(
                'data.locations.areas',
                []
            );

        $this->assertDatabaseHas('user_plants', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->target->id,
            'plant_id' => $this->plantA->id,
        ]);

        $this->assertSame(1, $this->auditCount());
    }

    public function test_assigns_area_without_assigning_parent_plant(): void
    {
        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [],
                    [$this->areaA->id]
                )
            )
            ->assertOk()
            ->assertJsonPath(
                'data.locations.plants',
                []
            )
            ->assertJsonCount(
                1,
                'data.locations.areas'
            )
            ->assertJsonPath(
                'data.locations.areas.0.id',
                $this->areaA->id
            )
            ->assertJsonPath(
                'data.locations.areas.0.plant_id',
                $this->plantA->id
            );

        $this->assertDatabaseHas('user_areas', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->target->id,
            'area_id' => $this->areaA->id,
        ]);

        $this->assertDatabaseMissing('user_plants', [
            'user_id' => $this->target->id,
            'plant_id' => $this->plantA->id,
        ]);
    }

    public function test_replaces_existing_assignments(): void
    {
        $this->assignPlantTo(
            $this->target,
            $this->plantA
        );

        $this->assignAreaTo(
            $this->target,
            $this->areaA
        );

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantB->id],
                    [$this->areaB->id]
                )
            )
            ->assertOk()
            ->assertJsonCount(
                1,
                'data.locations.plants'
            )
            ->assertJsonCount(
                1,
                'data.locations.areas'
            )
            ->assertJsonPath(
                'data.locations.plants.0.id',
                $this->plantB->id
            )
            ->assertJsonPath(
                'data.locations.areas.0.id',
                $this->areaB->id
            );

        $this->assertDatabaseMissing('user_plants', [
            'user_id' => $this->target->id,
            'plant_id' => $this->plantA->id,
        ]);

        $this->assertDatabaseMissing('user_areas', [
            'user_id' => $this->target->id,
            'area_id' => $this->areaA->id,
        ]);

        $this->assertDatabaseHas('user_plants', [
            'user_id' => $this->target->id,
            'plant_id' => $this->plantB->id,
        ]);

        $this->assertDatabaseHas('user_areas', [
            'user_id' => $this->target->id,
            'area_id' => $this->areaB->id,
        ]);
    }

    public function test_removes_all_assignments_without_deleting_locations(): void
    {
        $this->assignPlantTo(
            $this->target,
            $this->plantA
        );

        $this->assignAreaTo(
            $this->target,
            $this->areaA
        );

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload()
            )
            ->assertOk()
            ->assertJsonPath(
                'data.locations.plants',
                []
            )
            ->assertJsonPath(
                'data.locations.areas',
                []
            );

        $this->assertDatabaseMissing('user_plants', [
            'user_id' => $this->target->id,
            'organization_id' => $this->organization->id,
        ]);

        $this->assertDatabaseMissing('user_areas', [
            'user_id' => $this->target->id,
            'organization_id' => $this->organization->id,
        ]);

        $this->assertDatabaseHas('plants', [
            'id' => $this->plantA->id,
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas('areas', [
            'id' => $this->areaA->id,
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->target->id,
            'status' => true,
        ]);
    }

    public function test_replacement_is_idempotent(): void
    {
        $payload = $this->payload(
            [$this->plantA->id],
            [$this->areaA->id]
        );

        for ($i = 0; $i < 2; $i++) {
            $this->asAdministrator()
                ->putJson(
                    $this->url(),
                    $payload
                )
                ->assertOk()
                ->assertJsonCount(
                    1,
                    'data.locations.plants'
                )
                ->assertJsonCount(
                    1,
                    'data.locations.areas'
                );
        }

        $this->assertSame(
            1,
            DB::table('user_plants')
                ->where('user_id', $this->target->id)
                ->where('plant_id', $this->plantA->id)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('user_areas')
                ->where('user_id', $this->target->id)
                ->where('area_id', $this->areaA->id)
                ->count()
        );

        $this->assertSame(1, $this->auditCount());
    }

    public function test_assignment_order_does_not_generate_additional_audit(): void
    {
        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [
                        $this->plantA->id,
                        $this->plantB->id,
                    ],
                    [
                        $this->areaA->id,
                        $this->areaB->id,
                    ]
                )
            )
            ->assertOk();

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [
                        $this->plantB->id,
                        $this->plantA->id,
                    ],
                    [
                        $this->areaB->id,
                        $this->areaA->id,
                    ]
                )
            )
            ->assertOk();

        $this->assertSame(1, $this->auditCount());
    }

    /*
    |--------------------------------------------------------------------------
    | Autenticación y autorización
    |--------------------------------------------------------------------------
    */

    public function test_requires_authentication(): void
    {
        $this->putJson(
            $this->url(),
            $this->payload()
        )->assertUnauthorized();
    }

    public function test_requires_all_three_permissions(): void
    {
        $permissionSets = [
            [],
            [
                Permission::UsersManage,
                Permission::PlantsManage,
            ],
            [
                Permission::UsersManage,
                Permission::PlantsView,
            ],
            [
                Permission::PlantsManage,
                Permission::PlantsView,
            ],
        ];

        foreach ($permissionSets as $permissions) {
            $actor = $this->createMember(
                $permissions
            );

            $this->asMember($actor)
                ->putJson(
                    $this->url(),
                    $this->payload()
                )
                ->assertForbidden();
        }

        $this->assertSame(0, $this->auditCount());
    }

    public function test_cannot_modify_more_privileged_member(): void
    {
        $actor = $this->createMember(
            $this->locationPermissions()
        );

        $target = $this->createMember([
            Permission::RolesManage,
        ]);

        $this->asMember($actor)
            ->putJson(
                $this->url($target),
                $this->payload()
            )
            ->assertForbidden();

        $this->assertSame(0, $this->auditCount());
    }

    public function test_revoked_member_cannot_receive_locations(): void
    {
        OrganizationMembership::query()
            ->forOrganization($this->organization->id)
            ->forUser($this->target->id)
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
            ]);

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantA->id]
                )
            )
            ->assertForbidden();

        $this->assertDatabaseMissing('user_plants', [
            'user_id' => $this->target->id,
        ]);
    }

    public function test_inactive_account_cannot_receive_locations(): void
    {
        $this->target->status = false;
        $this->target->save();

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantA->id]
                )
            )
            ->assertForbidden();

        $this->assertDatabaseMissing('user_plants', [
            'user_id' => $this->target->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Alcance administrativo
    |--------------------------------------------------------------------------
    */

    public function test_limited_actor_can_assign_locations_inside_scope(): void
    {
        $actor = $this->createMember(
            $this->locationPermissions()
        );

        $this->assignPlantTo(
            $actor,
            $this->plantA
        );

        $this->asMember($actor)
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantA->id],
                    [$this->areaA->id]
                )
            )
            ->assertOk()
            ->assertJsonPath(
                'data.locations.plants.0.id',
                $this->plantA->id
            )
            ->assertJsonPath(
                'data.locations.areas.0.id',
                $this->areaA->id
            );
    }

    public function test_actor_with_area_scope_can_assign_area_but_not_plant(): void
    {
        $actor = $this->createMember(
            $this->locationPermissions()
        );

        $this->assignAreaTo(
            $actor,
            $this->areaA
        );

        $this->asMember($actor)
            ->putJson(
                $this->url(),
                $this->payload(
                    [],
                    [$this->areaA->id]
                )
            )
            ->assertOk();

        $this->asMember($actor)
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantA->id],
                    [$this->areaA->id]
                )
            )
            ->assertForbidden();

        $this->assertDatabaseMissing('user_plants', [
            'user_id' => $this->target->id,
            'plant_id' => $this->plantA->id,
        ]);

        $this->assertDatabaseHas('user_areas', [
            'user_id' => $this->target->id,
            'area_id' => $this->areaA->id,
        ]);
    }

    public function test_cannot_assign_plant_outside_actor_scope(): void
    {
        $actor = $this->createMember(
            $this->locationPermissions()
        );

        $this->assignPlantTo(
            $actor,
            $this->plantA
        );

        $this->asMember($actor)
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantB->id]
                )
            )
            ->assertForbidden();

        $this->assertDatabaseMissing('user_plants', [
            'user_id' => $this->target->id,
            'plant_id' => $this->plantB->id,
        ]);
    }

    public function test_cannot_assign_area_outside_actor_scope(): void
    {
        $actor = $this->createMember(
            $this->locationPermissions()
        );

        $this->assignPlantTo(
            $actor,
            $this->plantA
        );

        $this->asMember($actor)
            ->putJson(
                $this->url(),
                $this->payload(
                    [],
                    [$this->areaB->id]
                )
            )
            ->assertForbidden();

        $this->assertDatabaseMissing('user_areas', [
            'user_id' => $this->target->id,
            'area_id' => $this->areaB->id,
        ]);
    }

    public function test_hidden_existing_plant_prevents_complete_replacement(): void
    {
        $actor = $this->createMember(
            $this->locationPermissions()
        );

        $this->assignPlantTo(
            $actor,
            $this->plantA
        );

        $this->assignPlantTo(
            $this->target,
            $this->plantA
        );

        $this->assignPlantTo(
            $this->target,
            $this->plantB
        );

        $this->asMember($actor)
            ->getJson('/api/users/'.$this->target->id)
            ->assertOk()
            ->assertJsonCount(
                1,
                'data.locations.plants'
            );

        $this->asMember($actor)
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantA->id]
                )
            )
            ->assertForbidden();

        $this->assertDatabaseHas('user_plants', [
            'user_id' => $this->target->id,
            'plant_id' => $this->plantB->id,
        ]);

        $this->assertSame(0, $this->auditCount());
    }

    public function test_hidden_existing_area_prevents_complete_replacement(): void
    {
        $actor = $this->createMember(
            $this->locationPermissions()
        );

        $this->assignAreaTo(
            $actor,
            $this->areaA
        );

        $this->assignAreaTo(
            $this->target,
            $this->areaB
        );

        $this->asMember($actor)
            ->putJson(
                $this->url(),
                $this->payload(
                    [],
                    [$this->areaA->id]
                )
            )
            ->assertForbidden();

        $this->assertDatabaseHas('user_areas', [
            'user_id' => $this->target->id,
            'area_id' => $this->areaB->id,
        ]);

        $this->assertDatabaseMissing('user_areas', [
            'user_id' => $this->target->id,
            'area_id' => $this->areaA->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Aislamiento multi-tenancy
    |--------------------------------------------------------------------------
    */

    public function test_cannot_modify_user_from_another_organization(): void
    {
        $foreignOrganization = $this->clientOrganization(
            'FOREIGN-LOCATION-USER'
        );

        $foreignUser = $this->user(
            $foreignOrganization
        );

        $this->asAdministrator()
            ->putJson(
                $this->url($foreignUser),
                $this->payload()
            )
            ->assertNotFound();
    }

    public function test_cannot_assign_foreign_plant_or_area(): void
    {
        $foreignOrganization = $this->clientOrganization(
            'FOREIGN-LOCATIONS'
        );

        [$foreignPlant, $foreignArea] = app(
            TenantContext::class
        )->runAs(
            $foreignOrganization->id,
            function () use ($foreignOrganization): array {
                $plant = $this->plant(
                    $foreignOrganization,
                    'FOREIGN-PLANT'
                );

                return [
                    $plant,
                    $this->area(
                        $plant,
                        'FOREIGN-AREA'
                    ),
                ];
            }
        );

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [$foreignPlant->id]
                )
            )
            ->assertNotFound();

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [],
                    [$foreignArea->id]
                )
            )
            ->assertNotFound();

        $this->assertDatabaseMissing('user_plants', [
            'user_id' => $this->target->id,
        ]);

        $this->assertDatabaseMissing('user_areas', [
            'user_id' => $this->target->id,
        ]);
    }

    public function test_replacement_preserves_assignments_in_other_organizations(): void
    {
        $foreignOrganization = $this->clientOrganization(
            'SHARED-LOCATION-USER'
        );

        OrganizationMembership::create([
            'user_id' => $this->target->id,
            'organization_id' => $foreignOrganization->id,
            'status' => 'active',
        ]);

        [$foreignPlant, $foreignArea] = app(
            TenantContext::class
        )->runAs(
            $foreignOrganization->id,
            function () use ($foreignOrganization): array {
                $plant = $this->plant(
                    $foreignOrganization,
                    'SHARED-PLANT'
                );

                $area = $this->area(
                    $plant,
                    'SHARED-AREA'
                );

                $this->target->assignPlant($plant);
                $this->target->assignArea($area);

                return [$plant, $area];
            }
        );

        $this->assignPlantTo(
            $this->target,
            $this->plantA
        );

        $this->assignAreaTo(
            $this->target,
            $this->areaA
        );

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload()
            )
            ->assertOk()
            ->assertJsonPath(
                'data.locations.plants',
                []
            )
            ->assertJsonPath(
                'data.locations.areas',
                []
            );

        $this->assertDatabaseHas('user_plants', [
            'organization_id' => $foreignOrganization->id,
            'user_id' => $this->target->id,
            'plant_id' => $foreignPlant->id,
        ]);

        $this->assertDatabaseHas('user_areas', [
            'organization_id' => $foreignOrganization->id,
            'user_id' => $this->target->id,
            'area_id' => $foreignArea->id,
        ]);

        $this->assertDatabaseMissing('user_plants', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->target->id,
        ]);

        $this->assertDatabaseMissing('user_areas', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->target->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validación de disponibilidad
    |--------------------------------------------------------------------------
    */

    public function test_cannot_assign_inactive_plant(): void
    {
        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $this->plantA->update([
                    'status' => false,
                ]);
            }
        );

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantA->id]
                )
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'plant_ids',
            ]);
    }

    public function test_cannot_assign_inactive_area(): void
    {
        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $this->areaA->update([
                    'status' => false,
                ]);
            }
        );

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [],
                    [$this->areaA->id]
                )
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'area_ids',
            ]);
    }

    public function test_cannot_assign_area_when_parent_plant_is_inactive(): void
    {
        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $this->plantA->update([
                    'status' => false,
                ]);
            }
        );

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [],
                    [$this->areaA->id]
                )
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'area_ids',
            ]);
    }

    public function test_can_retain_existing_inactive_assignments(): void
    {
        $this->assignPlantTo(
            $this->target,
            $this->plantA
        );

        $this->assignAreaTo(
            $this->target,
            $this->areaA
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $this->plantA->update([
                    'status' => false,
                ]);

                $this->areaA->update([
                    'status' => false,
                ]);
            }
        );

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantA->id],
                    [$this->areaA->id]
                )
            )
            ->assertOk()
            ->assertJsonPath(
                'data.locations.plants.0.status',
                false
            )
            ->assertJsonPath(
                'data.locations.areas.0.status',
                false
            );

        $this->assertSame(0, $this->auditCount());
    }

    public function test_can_remove_existing_inactive_assignments(): void
    {
        $this->assignPlantTo(
            $this->target,
            $this->plantA
        );

        $this->assignAreaTo(
            $this->target,
            $this->areaA
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $this->plantA->update([
                    'status' => false,
                ]);

                $this->areaA->update([
                    'status' => false,
                ]);
            }
        );

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload()
            )
            ->assertOk()
            ->assertJsonPath(
                'data.locations.plants',
                []
            )
            ->assertJsonPath(
                'data.locations.areas',
                []
            );

        $this->assertDatabaseMissing('user_plants', [
            'user_id' => $this->target->id,
        ]);

        $this->assertDatabaseMissing('user_areas', [
            'user_id' => $this->target->id,
        ]);
    }

    public function test_rejects_new_assignment_to_soft_deleted_location(): void
    {
        DB::table('plants')
            ->where('id', $this->plantA->id)
            ->update([
                'deleted_at' => now(),
            ]);

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantA->id]
                )
            )
            ->assertNotFound();
    }

    public function test_existing_soft_deleted_assignment_requires_review(): void
    {
        $this->assignPlantTo(
            $this->target,
            $this->plantA
        );

        DB::table('plants')
            ->where('id', $this->plantA->id)
            ->update([
                'deleted_at' => now(),
            ]);

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload()
            )
            ->assertStatus(409);

        $this->assertDatabaseHas('user_plants', [
            'user_id' => $this->target->id,
            'plant_id' => $this->plantA->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validación del contrato de entrada
    |--------------------------------------------------------------------------
    */

    public function test_requires_both_assignment_arrays(): void
    {
        $this->asAdministrator()
            ->putJson(
                $this->url(),
                ['plant_ids' => []]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'area_ids',
            ]);

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                ['area_ids' => []]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'plant_ids',
            ]);
    }

    public function test_rejects_invalid_uuids(): void
    {
        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    ['invalid-uuid'],
                    ['invalid-uuid']
                )
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'plant_ids.0',
                'area_ids.0',
            ]);
    }

    public function test_rejects_duplicate_location_ids(): void
    {
        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [
                        $this->plantA->id,
                        $this->plantA->id,
                    ],
                    [
                        $this->areaA->id,
                        $this->areaA->id,
                    ]
                )
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'plant_ids.0',
                'area_ids.0',
            ]);
    }

    public function test_rejects_unknown_fields(): void
    {
        $this->asAdministrator()
            ->putJson(
                $this->url(),
                [
                    ...$this->payload(),
                    'organization_id' => $this->organization->id,
                    'user_id' => $this->administrator->id,
                    'status' => true,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'organization_id',
                'user_id',
                'status',
            ]);
    }

    public function test_rejects_query_parameters(): void
    {
        $this->asAdministrator()
            ->putJson(
                $this->url().'?status=active',
                $this->payload()
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'query',
            ]);
    }

    public function test_rejects_non_array_assignment_fields(): void
    {
        $this->asAdministrator()
            ->putJson(
                $this->url(),
                [
                    'plant_ids' => (object) [],
                    'area_ids' => [],
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'plant_ids',
            ]);

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                [
                    'plant_ids' => [],
                    'area_ids' => null,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'area_ids',
            ]);
    }

    public function test_rejects_nonexistent_location_ids(): void
    {
        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [(string) Str::uuid7()]
                )
            )
            ->assertNotFound();

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [],
                    [(string) Str::uuid7()]
                )
            )
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Atomicidad y auditoría
    |--------------------------------------------------------------------------
    */

    public function test_invalid_area_preserves_existing_assignments(): void
    {
        $this->assignPlantTo(
            $this->target,
            $this->plantA
        );

        $this->assignAreaTo(
            $this->target,
            $this->areaA
        );

        app(TenantContext::class)->runAs(
            $this->organization->id,
            function (): void {
                $this->areaB->update([
                    'status' => false,
                ]);
            }
        );

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantB->id],
                    [$this->areaB->id]
                )
            )
            ->assertUnprocessable();

        $this->assertDatabaseHas('user_plants', [
            'user_id' => $this->target->id,
            'plant_id' => $this->plantA->id,
        ]);

        $this->assertDatabaseHas('user_areas', [
            'user_id' => $this->target->id,
            'area_id' => $this->areaA->id,
        ]);

        $this->assertDatabaseMissing('user_plants', [
            'user_id' => $this->target->id,
            'plant_id' => $this->plantB->id,
        ]);

        $this->assertDatabaseMissing('user_areas', [
            'user_id' => $this->target->id,
            'area_id' => $this->areaB->id,
        ]);

        $this->assertSame(0, $this->auditCount());
    }

    public function test_records_location_changes_in_audit_log(): void
    {
        $membership = OrganizationMembership::query()
            ->forOrganization($this->organization->id)
            ->forUser($this->target->id)
            ->firstOrFail();

        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload(
                    [$this->plantA->id],
                    [$this->areaA->id]
                )
            )
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.locations_updated',
            'auditable_id' => $membership->id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->administrator->id,
        ]);

        $this->assertSame(1, $this->auditCount());
    }

    public function test_empty_replacement_without_changes_does_not_create_audit(): void
    {
        $this->asAdministrator()
            ->putJson(
                $this->url(),
                $this->payload()
            )
            ->assertOk();

        $this->assertSame(0, $this->auditCount());
    }
}
