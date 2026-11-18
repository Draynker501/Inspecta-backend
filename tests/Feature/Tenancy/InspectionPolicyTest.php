<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Models\Inspection;
use App\Models\RolePermission;
use App\Policies\InspectionPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class InspectionPolicyTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_revision_exige_permiso_asignacion_tenant_y_separacion_de_identidad(): void
    {
        $org = $this->organization('INS-REVIEW');
        $reviewer = $this->user($org)->fresh();
        $owner = $this->user($org);
        app(TenantContext::class)->set($org->id);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $otherArea = $this->area($plant, 'B');
        $role = $this->role($org, 'Administrador');
        $reviewer->assignRole($role);
        $inspection = new Inspection([
            'organization_id' => $org->id, 'user_id' => $owner->id,
            'plant_id' => $plant->id, 'area_id' => $area->id,
        ]);
        $policy = new InspectionPolicy;
        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'user_id' => $reviewer->id, 'area_id' => $area->id,
        ]);
        $this->assertFalse($policy->review($reviewer, $inspection));
        RolePermission::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'role_id' => $role->id, 'permission' => Permission::LocationsViewAll->value,
        ]);
        $this->assertTrue($reviewer->hasPermission(Permission::LocationsViewAll));
        $this->assertFalse($policy->review($reviewer, $inspection));
        RolePermission::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'role_id' => $role->id, 'permission' => Permission::InspectionsManage->value,
        ]);
        $this->assertTrue($policy->review($reviewer, $inspection));
        foreach ([Permission::InspectionsExecute] as $permission) {
            RolePermission::create([
                'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
                'role_id' => $role->id, 'permission' => $permission->value,
            ]);
        }
        $this->assertTrue($policy->review($reviewer, $inspection));
        $inspection->user_id = $reviewer->id;
        $this->assertFalse($policy->review($reviewer, $inspection));
        $inspection->user_id = $owner->id;
        $inspection->area_id = $otherArea->id;
        $this->assertFalse($policy->review($reviewer, $inspection));
        DB::table('user_plants')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'user_id' => $reviewer->id, 'plant_id' => $plant->id,
        ]);
        $this->assertTrue($policy->review($reviewer, $inspection));
        $reviewer->forceFill(['status' => false])->save();
        $this->assertFalse($policy->review($reviewer, $inspection));
        $reviewer->forceFill(['status' => true])->save();
        DB::table('organization_memberships')->where('organization_id', $org->id)
            ->where('user_id', $reviewer->id)->update(['status' => 'revoked']);
        $this->assertFalse($policy->review($reviewer, $inspection));
        DB::table('organization_memberships')->where('organization_id', $org->id)
            ->where('user_id', $reviewer->id)->update(['status' => 'active']);
        $otherOrg = $this->organization('INS-REVIEW-OTHER');
        app(TenantContext::class)->set($otherOrg->id);
        $this->assertFalse($policy->review($reviewer, $inspection));
        app(TenantContext::class)->clear();
        $this->assertFalse($policy->review($reviewer, $inspection));
        app(TenantContext::class)->set($org->id);
        $org->update(['status' => false]);
        $this->assertFalse($policy->review($reviewer, $inspection));
    }

    public function test_rol_de_plataforma_no_otorga_revision_en_cliente(): void
    {
        $operator = $this->operatorOrganization();
        $client = $this->clientOrganization('REVIEW-CLIENT');
        $actor = $this->platformConsultant($operator, [$client]);
        $owner = $this->user($client);
        app(TenantContext::class)->set($client->id);
        $plant = $this->plant($client, 'P');
        $area = $this->area($plant, 'A');
        $inspection = new Inspection([
            'organization_id' => $client->id, 'user_id' => $owner->id,
            'plant_id' => $plant->id, 'area_id' => $area->id,
        ]);
        $this->assertFalse($actor->hasPermission(Permission::InspectionsManage));
        $this->assertFalse((new InspectionPolicy)->review($actor, $inspection));
        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $client->id,
            'user_id' => $actor->id, 'area_id' => $area->id,
        ]);
        $this->assertFalse((new InspectionPolicy)->review($actor, $inspection));
    }

    public function test_alcance_operativo_no_se_hereda_de_view_all_y_admite_asignacion_de_planta(): void
    {
        $org = $this->organization('INS-SCOPE');
        $user = $this->user($org);
        app(TenantContext::class)->set($org->id);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $otherPlant = $this->plant($org, 'Q');
        $otherArea = $this->area($otherPlant, 'B');
        $policy = new InspectionPolicy;

        $this->assertFalse($policy->hasLocationAccess($user, $plant->id, $area->id));
        DB::table('user_plants')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'user_id' => $user->id, 'plant_id' => $plant->id,
        ]);
        $this->assertTrue($policy->hasLocationAccess($user, $plant->id, $area->id));
        $this->assertFalse($policy->hasLocationAccess($user, $otherPlant->id, $otherArea->id));
        DB::table('organization_memberships')->where('organization_id', $org->id)
            ->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->assertFalse($policy->hasLocationAccess($user, $plant->id, $area->id));
        app(TenantContext::class)->clear();
        $this->assertFalse($policy->hasLocationAccess($user, $plant->id, $area->id));
    }

    public function test_permisos_propiedad_y_asignacion_de_area_son_independientes(): void
    {
        $org = $this->organization('INS-POLICY');
        $user = $this->user($org);
        $other = $this->user($org);
        app(TenantContext::class)->set($org->id);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $sibling = $this->area($plant, 'B');
        $role = $this->role($org, 'personalizado');
        $user->assignRole($role);
        foreach ([Permission::InspectionsExecute, Permission::LocationsViewAll] as $permission) {
            RolePermission::create([
                'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
                'role_id' => $role->id, 'permission' => $permission->value,
            ]);
        }
        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'user_id' => $user->id, 'area_id' => $area->id,
        ]);
        $inspection = new Inspection([
            'organization_id' => $org->id, 'user_id' => $user->id,
            'plant_id' => $plant->id, 'area_id' => $area->id,
        ]);
        $policy = new InspectionPolicy;
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->view($user, $inspection));
        $inspection->area_id = $sibling->id;
        $this->assertFalse($policy->view($user, $inspection));
        $inspection->area_id = $area->id;
        $inspection->user_id = $other->id;
        $this->assertFalse($policy->view($user, $inspection));
        RolePermission::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'role_id' => $role->id, 'permission' => Permission::InspectionsManage->value,
        ]);
        $this->assertTrue($policy->view($user, $inspection));
        $area->delete();
        $this->assertTrue($policy->view($user, $inspection));
        $org->update(['status' => false]);
        $this->assertFalse($policy->view($user, $inspection));
        $this->assertFalse($policy->create($user));
    }
}
