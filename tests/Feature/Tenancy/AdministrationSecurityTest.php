<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AdministrationSecurityService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class AdministrationSecurityTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private Organization $organization;

    private TenantContext $context;

    private AdministrationSecurityService $security;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->clientOrganization(
            'ADMIN-SECURITY'
        );

        $this->context = app(TenantContext::class);

        $this->context->set($this->organization->id);

        $this->security = app(
            AdministrationSecurityService::class
        );
    }

    protected function tearDown(): void
    {
        $this->context->clear();

        parent::tearDown();
    }

    /**
     * Crea un rol de pruebas y concede los permisos indicados.
     */
    private function createRole(
        array $permissions = [],
        ?string $systemKey = null
    ): Role {
        $role = $this->role(
            $this->organization,
            'Security-'.Str::random(10)
        );

        foreach ($permissions as $permission) {
            RolePermission::create([
                'id' => (string) Str::uuid7(),
                'role_id' => $role->id,
                'organization_id' => $this->organization->id,
                'permission' => $permission->value,
            ]);
        }

        // Simula la configuración inicial de un rol base.
        if ($systemKey !== null) {
            DB::table('roles')
                ->where('id', $role->id)
                ->where(
                    'organization_id',
                    $this->organization->id
                )
                ->update([
                    'system_key' => $systemKey,
                ]);
        }

        return $role->refresh();
    }

    /**
     * Crea un usuario con los permisos indicados.
     */
    private function createMember(
        array $permissions = [],
        ?string $systemKey = null
    ): User {
        $user = $this->user($this->organization);

        $role = $this->createRole(
            $permissions,
            $systemKey
        );

        $user->assignRole($role);

        return $user;
    }

    /**
     * Crea un administrador base con todos los permisos.
     */
    private function createAdministrator(): User
    {
        return $this->createMember(
            Permission::cases(),
            'admin'
        );
    }

    public function test_inactive_role_does_not_grant_permissions(): void
    {
        $user = $this->user($this->organization);

        $role = $this->createRole([
            Permission::UsersManage,
        ]);

        $user->assignRole($role);

        $this->assertTrue(
            $user->hasPermission(Permission::UsersManage)
        );

        $this->assertTrue(
            $user->hasRole($role->name)
        );

        $role->update(['status' => false]);

        $this->assertFalse(
            $user->hasPermission(Permission::UsersManage)
        );

        $this->assertFalse(
            $user->hasRole($role->name)
        );

        // La asignación debe conservarse.
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_reactivating_role_restores_permissions(): void
    {
        $user = $this->user($this->organization);

        $role = $this->createRole([
            Permission::UsersManage,
        ]);

        $user->assignRole($role);

        $role->update(['status' => false]);

        $this->assertFalse(
            $user->hasPermission(Permission::UsersManage)
        );

        $role->update(['status' => true]);

        $this->assertTrue(
            $user->hasPermission(Permission::UsersManage)
        );
    }

    public function test_other_active_roles_preserve_shared_permissions(): void
    {
        $user = $this->user($this->organization);

        $roleA = $this->createRole([
            Permission::UsersManage,
        ]);

        $roleB = $this->createRole([
            Permission::UsersManage,
        ]);

        $user->assignRole($roleA);
        $user->assignRole($roleB);

        $roleA->update(['status' => false]);

        $this->assertTrue(
            $user->hasPermission(Permission::UsersManage)
        );

        $roleB->update(['status' => false]);

        $this->assertFalse(
            $user->hasPermission(Permission::UsersManage)
        );
    }

    public function test_inactive_role_cannot_be_assigned(): void
    {
        $user = $this->user($this->organization);

        $role = $this->createRole();

        $role->update(['status' => false]);

        $this->expectException(ValidationException::class);

        $user->assignRole($role);
    }

    public function test_actor_can_delegate_owned_permissions(): void
    {
        $actor = $this->createMember([
            Permission::RolesManage,
            Permission::PlantsView,
        ]);

        $this->security->ensureCanDelegatePermissions(
            $actor,
            [
                Permission::PlantsView->value,
            ]
        );

        $this->assertTrue(
            $actor->hasPermission(Permission::PlantsView)
        );
    }

    public function test_actor_cannot_delegate_unowned_permissions(): void
    {
        $actor = $this->createMember([
            Permission::RolesManage,
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage(
            'No puedes administrar permisos que no posees.'
        );

        $this->security->ensureCanDelegatePermissions(
            $actor,
            [
                Permission::UsersManage->value,
            ]
        );
    }

    public function test_unknown_permission_is_rejected(): void
    {
        $actor = $this->createAdministrator();

        $this->expectException(ValidationException::class);

        $this->security->ensureCanDelegatePermissions(
            $actor,
            ['invalid.permission']
        );
    }

    public function test_system_role_cannot_be_modified(): void
    {
        $actor = $this->createAdministrator();

        $role = $actor->roles()
            ->where('roles.system_key', 'admin')
            ->firstOrFail();

        $this->expectException(ValidationException::class);

        $this->security->ensureCanManageRole(
            $actor,
            $role
        );
    }

    public function test_custom_role_can_be_managed(): void
    {
        $actor = $this->createAdministrator();

        $role = $this->createRole([
            Permission::PlantsView,
        ]);

        $this->security->ensureCanManageRole(
            $actor,
            $role
        );

        $this->assertNull($role->system_key);
    }

    public function test_actor_cannot_manage_more_privileged_role(): void
    {
        $actor = $this->createMember([
            Permission::RolesManage,
        ]);

        $role = $this->createRole([
            Permission::UsersManage,
        ]);

        $this->expectException(HttpException::class);

        $this->security->ensureCanManageRole(
            $actor,
            $role
        );
    }

    public function test_role_assignment_requires_both_permissions(): void
    {
        $actor = $this->createMember([
            Permission::UsersManage,
        ]);

        $role = $this->createRole();

        $this->expectException(HttpException::class);

        $this->security->ensureCanAssignRole(
            $actor,
            $role
        );
    }

    public function test_actor_cannot_assign_more_privileged_role(): void
    {
        $actor = $this->createMember([
            Permission::UsersManage,
            Permission::RolesManage,
        ]);

        $role = $this->createRole([
            Permission::MembershipsManage,
        ]);

        $this->expectException(HttpException::class);

        $this->security->ensureCanAssignRole(
            $actor,
            $role
        );
    }

    public function test_actor_cannot_manage_more_privileged_member(): void
    {
        $actor = $this->createMember([
            Permission::MembershipsManage,
        ]);

        $target = $this->createAdministrator();

        $this->expectException(HttpException::class);

        $this->security->ensureCanManageMember(
            $actor,
            $target
        );
    }

    public function test_self_revocation_is_rejected(): void
    {
        $actor = $this->createAdministrator();

        $this->expectException(ValidationException::class);

        $this->security->ensureNotSelfRevocation(
            $actor,
            $actor
        );
    }

    public function test_foreign_role_is_rejected(): void
    {
        $foreignOrganization = $this->clientOrganization(
            'ADMIN-FOREIGN'
        );

        $foreignRole = $this->context->runAs(
            $foreignOrganization->id,
            fn () => $this->role(
                $foreignOrganization,
                'Foreign role'
            )
        );

        try {
            $this->security->ensureTenant($foreignRole);

            $this->fail(
                'Se permitió acceder a un rol de otra organización.'
            );
        } catch (HttpException $exception) {
            $this->assertSame(
                404,
                $exception->getStatusCode()
            );
        }
    }

    public function test_revoked_membership_cannot_authorize_operations(): void
    {
        $actor = $this->createMember([
            Permission::UsersManage,
        ]);

        $membership = $this->security->getMembership(
            $actor->id
        );

        $membership->update([
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $this->expectException(HttpException::class);

        $this->security->authorize(
            $actor,
            Permission::UsersManage
        );
    }

    public function test_last_administrator_removal_is_rolled_back(): void
    {
        $administrator = $this->createAdministrator();

        $actor = $this->createMember(
            Permission::cases()
        );

        $membership = $this->security->getMembership(
            $administrator->id
        );

        try {
            $this->security->transaction(
                $actor,
                function () use ($actor, $membership) {
                    $this->security->authorize(
                        $actor,
                        Permission::MembershipsManage
                    );

                    $membership->update([
                        'status' => OrganizationMembership::STATUS_REVOKED,
                        'revoked_at' => now(),
                    ]);
                }
            );

            $this->fail(
                'Se permitió eliminar al último administrador.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'administration',
                $exception->errors()
            );
        }

        $this->assertTrue(
            $membership->refresh()->isActive()
        );

        $this->assertTrue(
            $administrator->hasPermission(
                Permission::UsersManage
            )
        );
    }

    public function test_operation_succeeds_when_another_administrator_remains(): void
    {
        $actor = $this->createAdministrator();

        $target = $this->user($this->organization);

        // Ambos usuarios comparten el mismo rol base.
        $adminRole = $actor->roles()
            ->where('roles.system_key', 'admin')
            ->firstOrFail();

        $target->assignRole($adminRole);

        $membership = $this->security->getMembership(
            $target->id
        );

        $this->security->transaction(
            $actor,
            function () use ($actor, $target, $membership) {
                $this->security->authorize(
                    $actor,
                    Permission::MembershipsManage
                );

                $this->security->ensureNotSelfRevocation(
                    $actor,
                    $target
                );

                $this->security->ensureCanManageMember(
                    $actor,
                    $target
                );

                $membership->update([
                    'status' => OrganizationMembership::STATUS_REVOKED,
                    'revoked_at' => now(),
                ]);
            }
        );

        $this->assertFalse(
            $membership->refresh()->isActive()
        );

        $this->assertTrue(
            $actor->hasPermission(Permission::UsersManage)
        );
    }
}
