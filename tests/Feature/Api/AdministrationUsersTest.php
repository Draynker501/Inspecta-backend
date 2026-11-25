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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class AdministrationUsersTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private Organization $organization;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->clientOrganization(
            'ADMIN-USERS'
        );

        $this->administrator = $this->createMember(
            $this->organization,
            Permission::cases(),
            'admin'
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

    private function createRole(
        Organization $organization,
        array $permissions = [],
        ?string $systemKey = null
    ): Role {
        return app(TenantContext::class)->runAs(
            $organization->id,
            function () use (
                $organization,
                $permissions,
                $systemKey
            ) {
                $role = $this->role(
                    $organization,
                    'Admin-Users-'.Str::random(10)
                );

                foreach ($permissions as $permission) {
                    RolePermission::create([
                        'id' => (string) Str::uuid7(),
                        'organization_id' => $organization->id,
                        'role_id' => $role->id,
                        'permission' => $permission->value,
                    ]);
                }

                if ($systemKey !== null) {
                    DB::table('roles')
                        ->where('id', $role->id)
                        ->where(
                            'organization_id',
                            $organization->id
                        )
                        ->update([
                            'system_key' => $systemKey,
                        ]);
                }

                return $role->refresh();
            }
        );
    }

    private function createMember(
        Organization $organization,
        array $permissions = [],
        ?string $systemKey = null
    ): User {
        $user = $this->user($organization);

        if ($permissions !== [] || $systemKey !== null) {
            $role = $this->createRole(
                $organization,
                $permissions,
                $systemKey
            );

            app(TenantContext::class)->runAs(
                $organization->id,
                fn () => $user->assignRole($role)
            );
        }

        return $user;
    }

    private function asAdministrator(): static
    {
        return $this->actingAsApiInOrganization(
            $this->administrator,
            $this->organization
        );
    }

    private function userPayload(
        array $overrides = []
    ): array {
        return array_replace([
            'name' => 'Usuario de prueba',
            'email' => 'nuevo.usuario@example.com',
            'password' => 'InspectaPrueba2026!',
            'password_confirmation' => 'InspectaPrueba2026!',
        ], $overrides);
    }

    private function membership(
        User $user,
        ?Organization $organization = null
    ): OrganizationMembership {
        return OrganizationMembership::query()
            ->forUser($user->id)
            ->forOrganization(
                ($organization ?? $this->organization)->id
            )
            ->firstOrFail();
    }

    private function revokeMembership(
        User $user,
        ?Organization $organization = null
    ): void {
        $this->membership(
            $user,
            $organization
        )->update([
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }

    private function membershipUrl(
        User $user
    ): string {
        return '/api/users/'.$user->id.'/membership/status';
    }

    /*
    |--------------------------------------------------------------------------
    | Listado, búsqueda y paginación
    |--------------------------------------------------------------------------
    */

    public function test_lists_members_of_current_organization(): void
    {
        $member = $this->user($this->organization);

        $foreignOrganization = $this->clientOrganization(
            'ADMIN-USERS-FOREIGN'
        );

        $foreignUser = $this->user(
            $foreignOrganization
        );

        $this->asAdministrator()
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment([
                'id' => $member->id,
                'email' => $member->email,
            ])
            ->assertJsonMissing([
                'id' => $foreignUser->id,
            ]);
    }

    public function test_lists_users_with_pagination(): void
    {
        $this->user($this->organization);
        $this->user($this->organization);
        $this->user($this->organization);

        $this->asAdministrator()
            ->getJson('/api/users?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_searches_users_by_name_and_email(): void
    {
        $member = $this->user($this->organization);

        $member->update([
            'name' => 'Inspector Especial',
            'email' => 'especial@example.com',
        ]);

        $this->asAdministrator()
            ->getJson('/api/users?q=Inspector%20Especial')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $member->id);

        $this->asAdministrator()
            ->getJson('/api/users?q=especial@example.com')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $member->id);

        $this->asAdministrator()
            ->getJson('/api/users?q=inexistente-99999')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_filters_memberships_by_status(): void
    {
        $active = $this->user($this->organization);

        $revoked = $this->user($this->organization);

        $this->revokeMembership($revoked);

        $this->asAdministrator()
            ->getJson('/api/users?status=active')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'id' => $active->id,
            ])
            ->assertJsonMissing([
                'id' => $revoked->id,
            ]);

        $this->asAdministrator()
            ->getJson('/api/users?status=revoked')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $revoked->id)
            ->assertJsonPath(
                'data.0.membership.status',
                'revoked'
            );
    }

    public function test_filters_users_by_role(): void
    {
        $member = $this->createMember(
            $this->organization,
            [Permission::InspectionsExecute]
        );

        $role = app(TenantContext::class)->runAs(
            $this->organization->id,
            fn () => $member->roles()->firstOrFail()
        );

        $this->asAdministrator()
            ->getJson('/api/users?role_id='.$role->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $member->id)
            ->assertJsonPath(
                'data.0.roles.0.id',
                $role->id
            );

        $this->asAdministrator()
            ->getJson(
                '/api/users?role_id='.(string) Str::uuid7()
            )
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | Consulta individual
    |--------------------------------------------------------------------------
    */

    public function test_shows_member_without_sensitive_attributes(): void
    {
        $member = $this->user($this->organization);

        $this->asAdministrator()
            ->getJson('/api/users/'.$member->id)
            ->assertOk()
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.name', $member->name)
            ->assertJsonPath('data.email', $member->email)
            ->assertJsonPath('data.account_status', true)
            ->assertJsonPath(
                'data.membership.status',
                'active'
            )
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.locations.plants', [])
            ->assertJsonPath('data.locations.areas', [])
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.organization_id');
    }

    public function test_nonexistent_user_returns_not_found(): void
    {
        $this->asAdministrator()
            ->getJson(
                '/api/users/'.(string) Str::uuid7()
            )
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Creación y validación de usuarios
    |--------------------------------------------------------------------------
    */

    public function test_creates_user_and_active_membership(): void
    {
        $payload = $this->userPayload();

        $response = $this->asAdministrator()
            ->postJson('/api/users', $payload)
            ->assertCreated()
            ->assertJsonPath(
                'data.name',
                $payload['name']
            )
            ->assertJsonPath(
                'data.email',
                $payload['email']
            )
            ->assertJsonPath(
                'data.account_status',
                true
            )
            ->assertJsonPath(
                'data.membership.status',
                'active'
            )
            ->assertJsonPath(
                'data.membership.revoked_at',
                null
            )
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.locations.plants', [])
            ->assertJsonPath('data.locations.areas', [])
            ->assertJsonMissingPath('data.password');

        $userId = $response->json('data.id');

        $user = User::query()->findOrFail($userId);

        $this->assertTrue(
            Hash::check(
                $payload['password'],
                $user->password
            )
        );

        $this->assertNotSame(
            $payload['password'],
            $user->password
        );

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => $payload['email'],
            'status' => true,
        ]);

        $this->assertDatabaseHas(
            'organization_memberships',
            [
                'user_id' => $userId,
                'organization_id' => $this->organization->id,
                'status' => 'active',
                'revoked_at' => null,
            ]
        );

        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $userId,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->administrator->id,
            'action' => 'user.created',
            'auditable_id' => $userId,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->administrator->id,
            'action' => 'membership.created',
            'auditable_id' => $response->json(
                'data.membership.id'
            ),
        ]);

        $auditValues = DB::table('audit_logs')
            ->where('auditable_id', $userId)
            ->where('action', 'user.created')
            ->value('new_values');

        $this->assertStringNotContainsString(
            $payload['password'],
            (string) $auditValues
        );

        $this->assertStringNotContainsString(
            $user->password,
            (string) $auditValues
        );
    }

    public function test_normalizes_name_and_email(): void
    {
        $this->asAdministrator()
            ->postJson(
                '/api/users',
                $this->userPayload([
                    'name' => '  Nuevo Usuario  ',
                    'email' => '  NUEVO@EXAMPLE.COM  ',
                ])
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.name',
                'Nuevo Usuario'
            )
            ->assertJsonPath(
                'data.email',
                'nuevo@example.com'
            );
    }

    public function test_rejects_duplicate_email_in_current_organization(): void
    {
        $member = $this->user($this->organization);

        $this->asAdministrator()
            ->postJson(
                '/api/users',
                $this->userPayload([
                    'email' => $member->email,
                ])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(
            1,
            User::query()
                ->where('email', $member->email)
                ->count()
        );
    }

    public function test_rejects_duplicate_email_from_another_organization(): void
    {
        $foreignOrganization = $this->clientOrganization(
            'ADMIN-USERS-DUPLICATE'
        );

        $foreignUser = $this->user(
            $foreignOrganization
        );

        $this->asAdministrator()
            ->postJson(
                '/api/users',
                $this->userPayload([
                    'email' => $foreignUser->email,
                ])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath(
                'errors.email.0',
                'No es posible registrar este correo electrónico.'
            );

        $this->assertDatabaseMissing(
            'organization_memberships',
            [
                'user_id' => $foreignUser->id,
                'organization_id' => $this->organization->id,
            ]
        );
    }

    public function test_rejects_invalid_user_data(): void
    {
        $this->asAdministrator()
            ->postJson('/api/users', [
                'name' => '',
                'email' => 'correo-invalido',
                'password' => '123',
                'password_confirmation' => '123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'email',
                'password',
            ]);
    }

    public function test_rejects_invalid_password_confirmation_and_length(): void
    {
        $this->asAdministrator()
            ->postJson(
                '/api/users',
                $this->userPayload([
                    'password_confirmation' => 'NoCoincide123!',
                ])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $longPassword = 'Aa1'.str_repeat('x', 70);

        $this->asAdministrator()
            ->postJson(
                '/api/users',
                $this->userPayload([
                    'password' => $longPassword,
                    'password_confirmation' => $longPassword,
                ])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseMissing('users', [
            'email' => 'nuevo.usuario@example.com',
        ]);
    }

    public function test_rejects_unauthorized_fields_and_query_parameters(): void
    {
        $this->asAdministrator()
            ->postJson(
                '/api/users',
                $this->userPayload([
                    'organization_id' => (string) Str::uuid7(),
                    'status' => true,
                    'role_id' => (string) Str::uuid7(),
                ])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'organization_id',
                'status',
                'role_id',
            ]);

        $this->asAdministrator()
            ->postJson(
                '/api/users?status=active',
                $this->userPayload()
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['query']);

        $this->assertDatabaseMissing('users', [
            'email' => 'nuevo.usuario@example.com',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Revocación y reactivación
    |--------------------------------------------------------------------------
    */

    public function test_revokes_membership_without_disabling_account(): void
    {
        $member = $this->createMember(
            $this->organization,
            [Permission::InspectionsExecute]
        );

        $role = app(TenantContext::class)->runAs(
            $this->organization->id,
            fn () => $member->roles()->firstOrFail()
        );

        $response = $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl($member),
                ['status' => 'revoked']
            )
            ->assertOk()
            ->assertJsonPath(
                'data.account_status',
                true
            )
            ->assertJsonPath(
                'data.membership.status',
                'revoked'
            )
            ->assertJsonPath(
                'data.roles.0.id',
                $role->id
            );

        $this->assertNotNull(
            $response->json(
                'data.membership.revoked_at'
            )
        );

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'status' => true,
        ]);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $member->id,
            'role_id' => $role->id,
            'organization_id' => $this->organization->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'membership.revoked',
            'auditable_id' => $this->membership($member)->id,
            'user_id' => $this->administrator->id,
            'organization_id' => $this->organization->id,
        ]);

        $this->assertFalse(
            $this->membership($member)->isActive()
        );
    }

    public function test_reactivates_membership_and_restores_permissions(): void
    {
        $member = $this->createMember(
            $this->organization,
            [Permission::InspectionsExecute]
        );

        $this->revokeMembership($member);

        $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl($member),
                ['status' => 'active']
            )
            ->assertOk()
            ->assertJsonPath(
                'data.membership.status',
                'active'
            )
            ->assertJsonPath(
                'data.membership.revoked_at',
                null
            );

        $this->assertTrue(
            $this->membership($member)->isActive()
        );

        $this->assertTrue(
            app(TenantContext::class)->runAs(
                $this->organization->id,
                fn () => $member->hasPermission(
                    Permission::InspectionsExecute
                )
            )
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'membership.reactivated',
            'auditable_id' => $this->membership($member)->id,
            'user_id' => $this->administrator->id,
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_membership_status_update_is_idempotent(): void
    {
        $member = $this->user($this->organization);

        $firstResponse = $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl($member),
                ['status' => 'revoked']
            )
            ->assertOk();

        $revokedAt = $firstResponse->json(
            'data.membership.revoked_at'
        );

        $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl($member),
                ['status' => 'revoked']
            )
            ->assertOk()
            ->assertJsonPath(
                'data.membership.revoked_at',
                $revokedAt
            );

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where(
                    'auditable_id',
                    $this->membership($member)->id
                )
                ->where(
                    'action',
                    'membership.revoked'
                )
                ->count()
        );
    }

    public function test_cannot_reactivate_inactive_global_account(): void
    {
        $member = $this->user($this->organization);

        $this->revokeMembership($member);

        $member->status = false;
        $member->save();

        $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl($member),
                ['status' => 'active']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertFalse(
            $this->membership($member)->isActive()
        );
    }

    public function test_rejects_invalid_membership_status(): void
    {
        $member = $this->user($this->organization);

        $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl($member),
                ['status' => 'inactive']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl($member),
                [
                    'status' => 'revoked',
                    'account_status' => false,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'account_status',
            ]);

        $this->assertTrue(
            $this->membership($member)->isActive()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Permisos y escalamiento de privilegios
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_user_cannot_access_administration(): void
    {
        $this->getJson('/api/users')
            ->assertUnauthorized();

        $this->postJson(
            '/api/users',
            $this->userPayload()
        )->assertUnauthorized();

        $this->getJson(
            '/api/users/'.$this->administrator->id
        )->assertUnauthorized();

        $this->patchJson(
            $this->membershipUrl($this->administrator),
            ['status' => 'revoked']
        )->assertUnauthorized();
    }

    public function test_user_without_permissions_cannot_access_endpoints(): void
    {
        $member = $this->user($this->organization);

        $this->actingAsApiInOrganization(
            $member,
            $this->organization
        )->getJson('/api/users')
            ->assertForbidden();

        $this->actingAsApiInOrganization(
            $member,
            $this->organization
        )->postJson(
            '/api/users',
            $this->userPayload()
        )->assertForbidden();

        $this->actingAsApiInOrganization(
            $member,
            $this->organization
        )->getJson(
            '/api/users/'.$member->id
        )->assertForbidden();

        $this->actingAsApiInOrganization(
            $member,
            $this->organization
        )->patchJson(
            $this->membershipUrl($member),
            ['status' => 'revoked']
        )->assertForbidden();
    }

    public function test_user_creation_requires_both_permissions(): void
    {
        $usersManager = $this->createMember(
            $this->organization,
            [Permission::UsersManage]
        );

        $membershipsManager = $this->createMember(
            $this->organization,
            [Permission::MembershipsManage]
        );

        foreach (
            [$usersManager, $membershipsManager] as $actor
        ) {
            $this->actingAsApiInOrganization(
                $actor,
                $this->organization
            )->getJson('/api/users')
                ->assertOk();

            $this->actingAsApiInOrganization(
                $actor,
                $this->organization
            )->getJson('/api/users/'.$actor->id)
                ->assertOk();

            $this->actingAsApiInOrganization(
                $actor,
                $this->organization
            )->postJson(
                '/api/users',
                $this->userPayload()
            )->assertForbidden();
        }

        $this->assertDatabaseMissing('users', [
            'email' => 'nuevo.usuario@example.com',
        ]);
    }

    public function test_membership_management_requires_memberships_permission(): void
    {
        $actor = $this->createMember(
            $this->organization,
            [Permission::UsersManage]
        );

        $target = $this->user($this->organization);

        $this->actingAsApiInOrganization(
            $actor,
            $this->organization
        )->patchJson(
            $this->membershipUrl($target),
            ['status' => 'revoked']
        )->assertForbidden();

        $this->assertTrue(
            $this->membership($target)->isActive()
        );

        $membershipManager = $this->createMember(
            $this->organization,
            [Permission::MembershipsManage]
        );

        $this->actingAsApiInOrganization(
            $membershipManager,
            $this->organization
        )->patchJson(
            $this->membershipUrl($target),
            ['status' => 'revoked']
        )->assertOk();

        $this->assertFalse(
            $this->membership($target)->isActive()
        );
    }

    public function test_administrator_cannot_revoke_own_membership(): void
    {
        $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl(
                    $this->administrator
                ),
                ['status' => 'revoked']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'membership',
            ]);

        $this->assertTrue(
            $this->membership(
                $this->administrator
            )->isActive()
        );
    }

    public function test_actor_cannot_manage_more_privileged_member(): void
    {
        $actor = $this->createMember(
            $this->organization,
            [Permission::MembershipsManage]
        );

        $target = $this->createMember(
            $this->organization,
            [Permission::RolesManage]
        );

        $this->actingAsApiInOrganization(
            $actor,
            $this->organization
        )->patchJson(
            $this->membershipUrl($target),
            ['status' => 'revoked']
        )->assertForbidden();

        $this->assertTrue(
            $this->membership($target)->isActive()
        );

        $this->revokeMembership($target);

        $this->actingAsApiInOrganization(
            $actor,
            $this->organization
        )->patchJson(
            $this->membershipUrl($target),
            ['status' => 'active']
        )->assertForbidden();

        $this->assertFalse(
            $this->membership($target)->isActive()
        );
    }

    public function test_custom_role_cannot_delegate_special_system_privileges(): void
    {
        $actor = $this->createMember(
            $this->organization,
            Permission::cases()
        );

        $supervisor = $this->createMember(
            $this->organization,
            [Permission::CorrectiveActionsVerify],
            'supervisor'
        );

        $this->revokeMembership($supervisor);

        // Tener todos los permisos no convierte al actor
        // en administrador base ni en verificador elegible.

        $this->actingAsApiInOrganization(
            $actor,
            $this->organization
        )->patchJson(
            $this->membershipUrl($this->administrator),
            ['status' => 'revoked']
        )->assertForbidden();

        $this->actingAsApiInOrganization(
            $actor,
            $this->organization
        )->patchJson(
            $this->membershipUrl($supervisor),
            ['status' => 'active']
        )->assertForbidden();

        $this->assertTrue(
            $this->membership(
                $this->administrator
            )->isActive()
        );

        $this->assertFalse(
            $this->membership($supervisor)->isActive()
        );

        $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl($supervisor),
                ['status' => 'active']
            )
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Aislamiento multi-tenancy y ubicaciones
    |--------------------------------------------------------------------------
    */

    public function test_foreign_user_cannot_be_consulted_or_modified(): void
    {
        $foreignOrganization = $this->clientOrganization(
            'ADMIN-USERS-ISOLATION'
        );

        $foreignUser = $this->user(
            $foreignOrganization
        );

        $this->asAdministrator()
            ->getJson('/api/users/'.$foreignUser->id)
            ->assertNotFound();

        $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl($foreignUser),
                ['status' => 'revoked']
            )
            ->assertNotFound();

        $this->assertTrue(
            $this->membership(
                $foreignUser,
                $foreignOrganization
            )->isActive()
        );
    }

    public function test_revocation_only_affects_selected_organization(): void
    {
        $foreignOrganization = $this->clientOrganization(
            'ADMIN-USERS-SHARED'
        );

        $member = $this->user($this->organization);

        OrganizationMembership::create([
            'user_id' => $member->id,
            'organization_id' => $foreignOrganization->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl($member),
                ['status' => 'revoked']
            )
            ->assertOk();

        $this->assertFalse(
            $this->membership($member)->isActive()
        );

        $this->assertTrue(
            $this->membership(
                $member,
                $foreignOrganization
            )->isActive()
        );

        $this->actingAsApiInOrganization(
            $member,
            $this->organization
        )->getJson('/api/auth/capabilities')
            ->assertForbidden();

        $this->actingAsApiInOrganization(
            $member,
            $foreignOrganization
        )->getJson('/api/auth/capabilities')
            ->assertOk()
            ->assertJsonPath(
                'organization_id',
                $foreignOrganization->id
            );
    }

    public function test_user_locations_respect_actor_visibility(): void
    {
        $target = $this->user($this->organization);

        $actor = $this->createMember(
            $this->organization,
            [
                Permission::UsersManage,
                Permission::PlantsView,
            ]
        );

        $locations = app(TenantContext::class)->runAs(
            $this->organization->id,
            function () use ($target, $actor) {
                $visiblePlant = $this->plant(
                    $this->organization,
                    'VISIBLE'
                );

                $hiddenPlant = $this->plant(
                    $this->organization,
                    'HIDDEN'
                );

                $visibleArea = $this->area(
                    $visiblePlant,
                    'VISIBLE-AREA'
                );

                $hiddenArea = $this->area(
                    $hiddenPlant,
                    'HIDDEN-AREA'
                );

                $target->assignPlant($visiblePlant);
                $target->assignPlant($hiddenPlant);
                $target->assignArea($visibleArea);
                $target->assignArea($hiddenArea);

                $actor->assignPlant($visiblePlant);

                return [
                    'visiblePlant' => $visiblePlant,
                    'hiddenPlant' => $hiddenPlant,
                    'visibleArea' => $visibleArea,
                    'hiddenArea' => $hiddenArea,
                ];
            }
        );

        $this->actingAsApiInOrganization(
            $actor,
            $this->organization
        )->getJson('/api/users/'.$target->id)
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
                $locations['visiblePlant']->id
            )
            ->assertJsonPath(
                'data.locations.areas.0.id',
                $locations['visibleArea']->id
            )
            ->assertJsonMissing([
                'id' => $locations['hiddenPlant']->id,
            ])
            ->assertJsonMissing([
                'id' => $locations['hiddenArea']->id,
            ]);

        $this->asAdministrator()
            ->getJson('/api/users/'.$target->id)
            ->assertOk()
            ->assertJsonCount(
                2,
                'data.locations.plants'
            )
            ->assertJsonCount(
                2,
                'data.locations.areas'
            );
    }

    public function test_cannot_restore_locations_outside_actor_scope(): void
    {
        $target = $this->user($this->organization);

        $actor = $this->createMember(
            $this->organization,
            [
                Permission::UsersManage,
                Permission::MembershipsManage,
                Permission::PlantsView,
            ]
        );

        $plant = app(TenantContext::class)->runAs(
            $this->organization->id,
            function () use ($target) {
                $plant = $this->plant(
                    $this->organization,
                    'RESTORE-SCOPE'
                );

                $target->assignPlant($plant);

                return $plant;
            }
        );

        $this->revokeMembership($target);

        $this->actingAsApiInOrganization(
            $actor,
            $this->organization
        )->patchJson(
            $this->membershipUrl($target),
            ['status' => 'active']
        )->assertForbidden();

        $this->assertFalse(
            $this->membership($target)->isActive()
        );

        $this->assertDatabaseHas('user_plants', [
            'user_id' => $target->id,
            'plant_id' => $plant->id,
            'organization_id' => $this->organization->id,
        ]);

        $this->asAdministrator()
            ->patchJson(
                $this->membershipUrl($target),
                ['status' => 'active']
            )
            ->assertOk()
            ->assertJsonPath(
                'data.membership.status',
                'active'
            )
            ->assertJsonPath(
                'data.locations.plants.0.id',
                $plant->id
            );
    }
}
