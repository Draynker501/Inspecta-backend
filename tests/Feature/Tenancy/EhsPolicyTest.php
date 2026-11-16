<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\OrganizationMembership;
use App\Models\RolePermission;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class EhsPolicyTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_checklist_y_normas_exigen_permisos_y_organizacion_activa(): void
    {
        $org = $this->organization('EHS-A');
        $other = $this->organization('EHS-B');
        $user = $this->user($org);
        app(TenantContext::class)->set($org->id);
        $role = $this->role($org, 'ehs-test');
        $user->assignRole($role);
        $gate = Gate::forUser($user);
        $checklist = new Checklist(['organization_id' => $org->id]);
        $foreign = new Checklist(['organization_id' => $other->id]);
        $norm = new Norm(['organization_id' => $org->id]);
        $requirement = new NormRequirement(['organization_id' => $org->id]);
        $foreignRequirement = new NormRequirement(['organization_id' => $other->id]);

        $this->assertFalse($gate->allows('viewAny', Checklist::class));
        $this->assertFalse($gate->allows('viewAny', Norm::class));
        $this->assertFalse($gate->allows('create', Checklist::class));
        $this->assertFalse($gate->allows('create', Norm::class));
        $this->assertFalse($gate->allows('linkNormAny', Checklist::class));
        $this->assertFalse($gate->allows('linkNorm', [$checklist, $requirement]));

        foreach ([Permission::ChecklistsView, Permission::NormsView] as $permission) {
            $this->grant($role->id, $org->id, $permission);
        }
        $this->assertTrue($gate->allows('view', $checklist));
        $this->assertTrue($gate->allows('view', $norm));
        $this->assertFalse($gate->allows('view', $foreign));
        $this->assertFalse($gate->allows('update', $checklist));
        $this->assertFalse($gate->allows('update', $norm));

        foreach ([Permission::ChecklistsManage, Permission::NormsManage] as $permission) {
            $this->grant($role->id, $org->id, $permission);
        }
        $this->assertTrue($gate->allows('create', Checklist::class));
        $this->assertTrue($gate->allows('update', $checklist));
        $this->assertTrue($gate->allows('update', $norm));
        $this->assertFalse($gate->allows('update', $foreign));
        $this->assertTrue($gate->allows('linkNormAny', Checklist::class));
        $this->assertTrue($gate->allows('linkNorm', [$checklist, $requirement]));
        $this->assertTrue($gate->allows('unlinkNorm', $checklist));
        $this->assertFalse($gate->allows('linkNorm', [$foreign, $requirement]));
        $this->assertFalse($gate->allows('linkNorm', [$checklist, $foreignRequirement]));

        app(TenantContext::class)->clear();
        $this->assertFalse($gate->allows('viewAny', Checklist::class));
        $this->assertFalse($gate->allows('update', $norm));
    }

    public function test_vincular_exige_lectura_de_normas_pero_desvincular_no(): void
    {
        $org = $this->organization('EHS-LINK');
        $user = $this->user($org);
        app(TenantContext::class)->set($org->id);
        $role = $this->role($org, 'ehs-link-test');
        $user->assignRole($role);
        $this->grant($role->id, $org->id, Permission::ChecklistsManage);
        $checklist = new Checklist(['organization_id' => $org->id]);
        $requirement = new NormRequirement(['organization_id' => $org->id]);
        $gate = Gate::forUser($user);

        $this->assertFalse($gate->allows('linkNormAny', Checklist::class));
        $this->assertFalse($gate->allows('linkNorm', [$checklist, $requirement]));
        $this->assertTrue($gate->allows('unlinkNorm', $checklist));
        $this->assertFalse($gate->allows('view', $checklist));
    }

    public function test_membresia_revocada_no_conserva_acceso(): void
    {
        $org = $this->organization('EHS-REVOKED');
        $user = $this->user($org);
        app(TenantContext::class)->set($org->id);
        $role = $this->role($org, 'ehs-revoked-test');
        $user->assignRole($role);
        $this->grant($role->id, $org->id, Permission::ChecklistsManage);
        $checklist = new Checklist(['organization_id' => $org->id]);
        $gate = Gate::forUser($user);
        $this->assertTrue($gate->allows('update', $checklist));

        OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $org->id)
            ->update(['status' => OrganizationMembership::STATUS_REVOKED]);

        $this->assertFalse($gate->allows('update', $checklist));
        $this->assertFalse($gate->allows('create', Checklist::class));
    }

    private function grant(string $roleId, string $organizationId, Permission $permission): void
    {
        RolePermission::create([
            'id' => (string) Str::uuid7(),
            'role_id' => $roleId,
            'organization_id' => $organizationId,
            'permission' => $permission->value,
        ]);
    }
}
