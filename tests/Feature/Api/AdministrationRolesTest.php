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
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class AdministrationRolesTest extends TestCase
{
    use RefreshDatabase, TenantFixtures;

    private Organization $organization;
    private User $administrator;
    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->clientOrganization('ADMIN-ROLES');
        $this->administrator = $this->user($this->organization);
        $this->adminRole = $this->makeRole($this->organization, Permission::cases(), 'admin');
        $this->assign($this->organization, $this->administrator, $this->adminRole);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function makeRole(Organization $org, array $permissions = [], ?string $key = null): Role
    {
        return app(TenantContext::class)->runAs($org->id, function () use ($org, $permissions, $key) {
            $role = $this->role($org, 'Role-'.Str::random(12));
            foreach ($permissions as $permission) {
                RolePermission::create([
                    'organization_id' => $org->id,
                    'role_id' => $role->id,
                    'permission' => $permission->value,
                ]);
            }
            if ($key !== null) {
                DB::table('roles')->where('id', $role->id)->update(['system_key' => $key]);
            }
            return $role->refresh();
        });
    }

    private function assign(Organization $org, User $user, Role $role): void
    {
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
    }

    private function asAdmin(): static
    {
        return $this->actingAsApiInOrganization($this->administrator, $this->organization);
    }

    private function limited(array $permissions): static
    {
        $user = $this->user($this->organization);
        $this->assign($this->organization, $user, $this->makeRole($this->organization, $permissions));
        return $this->actingAsApiInOrganization($user, $this->organization);
    }

    public function test_lists_only_current_tenant_roles_with_pagination(): void
    {
        $role = $this->makeRole($this->organization);
        $foreign = $this->makeRole($this->clientOrganization('FOREIGN-ROLES'));
        $this->asAdmin()->getJson('/api/roles?per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->asAdmin()->getJson('/api/roles?q='.$role->name)
            ->assertOk()->assertJsonPath('data.0.id', $role->id)
            ->assertJsonMissing(['id' => $foreign->id]);
        $this->asAdmin()->getJson('/api/roles/'.$foreign->id)->assertNotFound();
    }

    public function test_creates_and_updates_custom_role(): void
    {
        $id = $this->asAdmin()->postJson('/api/roles', ['name' => '  Auditor  '])
            ->assertCreated()->assertJsonPath('data.name', 'Auditor')
            ->assertJsonPath('data.system_key', null)->assertJsonPath('data.status', true)
            ->assertJsonPath('data.permissions', [])->json('data.id');
        $this->asAdmin()->patchJson('/api/roles/'.$id, ['description' => 'Auditor interno'])
            ->assertOk()->assertJsonPath('data.description', 'Auditor interno');
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.created', 'auditable_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.updated', 'auditable_id' => $id]);
    }

    public function test_rejects_duplicate_name_and_protected_fields(): void
    {
        $this->asAdmin()->postJson('/api/roles', ['name' => $this->adminRole->name])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->asAdmin()->postJson('/api/roles', ['name' => 'Custom', 'system_key' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors(['system_key']);
    }

    public function test_base_role_cannot_be_modified(): void
    {
        $url = '/api/roles/'.$this->adminRole->id;
        $this->asAdmin()->patchJson($url, ['name' => 'Changed'])->assertUnprocessable();
        $this->asAdmin()->patchJson($url.'/status', ['status' => false])->assertUnprocessable();
        $this->asAdmin()->putJson($url.'/permissions', ['permissions' => []])->assertUnprocessable();
    }

    public function test_catalog_and_read_authorization(): void
    {
        $this->asAdmin()->getJson('/api/permissions')->assertOk()->assertJsonCount(count(Permission::cases()), 'data');
        $this->limited([Permission::UsersManage])->getJson('/api/roles')->assertOk();
        $this->getJson('/api/permissions')->assertForbidden();
        $this->postJson('/api/roles', ['name' => 'Denied'])->assertForbidden();
    }

    public function test_replaces_permissions_and_rejects_invalid_values(): void
    {
        $role = $this->makeRole($this->organization, [Permission::PlantsView]);
        $url = '/api/roles/'.$role->id.'/permissions';
        $this->asAdmin()->putJson($url, ['permissions' => ['reports.view']])
            ->assertOk()->assertJsonPath('data.permissions', ['reports.view']);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $role->id, 'permission' => 'plants.view']);
        $this->asAdmin()->putJson($url, ['permissions' => ['invalid.permission']])->assertUnprocessable();
        $this->asAdmin()->putJson($url, ['permissions' => ['reports.view', 'reports.view']])->assertUnprocessable();
        $this->asAdmin()->putJson($url, ['permissions' => []])->assertOk()->assertJsonPath('data.permissions', []);
    }

    public function test_cannot_delegate_permission_not_owned(): void
    {
        $role = $this->makeRole($this->organization);
        $this->limited([Permission::RolesManage])->putJson('/api/roles/'.$role->id.'/permissions',
            ['permissions' => ['reports.view']])->assertForbidden();
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $role->id]);
    }

    public function test_deactivation_preserves_assignments_but_removes_effective_permission(): void
    {
        $role = $this->makeRole($this->organization, [Permission::ReportsView]);
        $member = $this->user($this->organization);
        $this->assign($this->organization, $member, $role);
        $this->asAdmin()->patchJson('/api/roles/'.$role->id.'/status', ['status' => false])
            ->assertOk()->assertJsonPath('data.status', false);
        $this->assertDatabaseHas('user_roles', ['user_id' => $member->id, 'role_id' => $role->id]);
        $this->assertFalse(app(TenantContext::class)->runAs($this->organization->id,
            fn () => $member->hasPermission(Permission::ReportsView)));
        $this->asAdmin()->patchJson('/api/roles/'.$role->id.'/status', ['status' => true])->assertOk();
        $this->assertTrue(app(TenantContext::class)->runAs($this->organization->id,
            fn () => $member->hasPermission(Permission::ReportsView)));
    }

    public function test_replaces_user_roles_and_is_idempotent(): void
    {
        $member = $this->user($this->organization);
        $old = $this->makeRole($this->organization);
        $new = $this->makeRole($this->organization, [Permission::ReportsView]);
        $this->assign($this->organization, $member, $old);
        $url = '/api/users/'.$member->id.'/roles';
        for ($i = 0; $i < 2; $i++) {
            $this->asAdmin()->putJson($url, ['role_ids' => [$new->id]])
                ->assertOk()->assertJsonCount(1, 'data.roles')->assertJsonPath('data.roles.0.id', $new->id);
        }
        $this->assertDatabaseMissing('user_roles', ['user_id' => $member->id, 'role_id' => $old->id]);
        $this->assertDatabaseHas('user_roles', ['user_id' => $member->id, 'role_id' => $new->id]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'user.roles_updated')->count());
        $this->asAdmin()->putJson($url, ['role_ids' => []])->assertOk()->assertJsonPath('data.roles', []);
    }

    public function test_rejects_foreign_role_without_removing_existing_assignment(): void
    {
        $member = $this->user($this->organization);
        $old = $this->makeRole($this->organization);
        $this->assign($this->organization, $member, $old);
        $foreign = $this->makeRole($this->clientOrganization('FOREIGN-ASSIGN'));
        $this->asAdmin()->putJson('/api/users/'.$member->id.'/roles', ['role_ids' => [$foreign->id]])
            ->assertNotFound();
        $this->assertDatabaseHas('user_roles', ['user_id' => $member->id, 'role_id' => $old->id]);
    }

    public function test_inactive_role_can_be_retained_but_not_newly_assigned(): void
    {
        $member = $this->user($this->organization);
        $other = $this->user($this->organization);
        $role = $this->makeRole($this->organization);
        $this->assign($this->organization, $member, $role);
        $this->asAdmin()->patchJson('/api/roles/'.$role->id.'/status', ['status' => false])->assertOk();
        $this->asAdmin()->putJson('/api/users/'.$member->id.'/roles', ['role_ids' => [$role->id]])->assertOk();
        $this->asAdmin()->putJson('/api/users/'.$other->id.'/roles', ['role_ids' => [$role->id]])->assertUnprocessable();
    }

    public function test_last_administrator_cannot_remove_own_role(): void
    {
        $this->asAdmin()->putJson('/api/users/'.$this->administrator->id.'/roles', ['role_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['administration']);
        $this->assertDatabaseHas('user_roles', ['user_id' => $this->administrator->id, 'role_id' => $this->adminRole->id]);
    }

    public function test_revoked_member_cannot_receive_roles(): void
    {
        $member = $this->user($this->organization);
        OrganizationMembership::query()->forOrganization($this->organization->id)->forUser($member->id)
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $role = $this->makeRole($this->organization);
        $this->asAdmin()->putJson('/api/users/'.$member->id.'/roles', ['role_ids' => [$role->id]])->assertForbidden();
    }

    public function test_assignment_requires_both_permissions_and_valid_ids(): void
    {
        $member = $this->user($this->organization);
        $role = $this->makeRole($this->organization);
        $url = '/api/users/'.$member->id.'/roles';
        $this->limited([Permission::UsersManage])->putJson($url, ['role_ids' => [$role->id]])->assertForbidden();
        $this->limited([Permission::RolesManage])->putJson($url, ['role_ids' => [$role->id]])->assertForbidden();
        $this->asAdmin()->putJson($url, ['role_ids' => [$role->id, $role->id]])->assertUnprocessable();
        $this->asAdmin()->putJson($url, ['role_ids' => ['invalid']])->assertUnprocessable();
    }
}
