<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Models\Plant;
use App\Models\RolePermission;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * Reglas de autorización de la infraestructura: contexto obligatorio,
 * misma organización y alcance por asignación para roles de campo.
 */
class TenantPolicyTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_admin_puede_gestionar_el_catalogo_de_su_organizacion(): void
    {
        $orgA = $this->organization('ORG-A');
        $admin = $this->admin($orgA);
        $plant = $this->plant($orgA, 'A-01');

        app(TenantContext::class)->set($orgA->id);

        $gate = Gate::forUser($admin);

        $this->assertTrue($gate->allows('viewAny', Plant::class));
        $this->assertTrue($gate->allows('view', $plant));
        $this->assertTrue($gate->allows('create', Plant::class));
        $this->assertTrue($gate->allows('update', $plant));
        $this->assertTrue($gate->allows('delete', $plant));
    }

    public function test_admin_no_puede_acceder_a_recursos_de_otra_organizacion(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $adminA = $this->admin($orgA);

        app(TenantContext::class)->clear();

        $plantB = $this->plant($orgB, 'B-01');

        app(TenantContext::class)->set($orgA->id);

        $gate = Gate::forUser($adminA);

        $this->assertFalse($gate->allows('view', $plantB));
        $this->assertFalse($gate->allows('update', $plantB));
        $this->assertFalse($gate->allows('delete', $plantB));
    }

    public function test_sin_contexto_organizacional_todo_se_deniega(): void
    {
        $orgA = $this->organization('ORG-A');
        $admin = $this->admin($orgA);
        $plant = $this->plant($orgA, 'A-01');

        app(TenantContext::class)->clear();

        $gate = Gate::forUser($admin);

        $this->assertFalse($gate->allows('viewAny', Plant::class));
        $this->assertFalse($gate->allows('view', $plant));
        $this->assertFalse($gate->allows('create', Plant::class));
        $this->assertFalse($gate->allows('update', $plant));
        $this->assertFalse($gate->allows('delete', $plant));
    }

    public function test_un_usuario_de_otra_organizacion_no_hereda_el_contexto(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $userB = $this->user($orgB);
        $plantA = $this->plant($orgA, 'A-01');

        app(TenantContext::class)->set($orgA->id);

        $gate = Gate::forUser($userB);

        $this->assertFalse($gate->allows('viewAny', Plant::class));
        $this->assertFalse($gate->allows('view', $plantA));
    }

    public function test_inspector_solo_ve_sus_plantas_asignadas(): void
    {
        $orgA = $this->organization('ORG-A');
        $inspector = $this->user($orgA);
        app(TenantContext::class)->set($orgA->id);
        $inspectorRole = $this->role($orgA, (string) config('tenancy.roles.inspector'));
        $inspector->assignRole($inspectorRole);
        RolePermission::firstOrCreate([
            'role_id' => $inspectorRole->id,
            'organization_id' => $orgA->id,
            'permission' => Permission::PlantsView->value,
        ], [
            'id' => (string) Str::uuid7(),
        ]);

        $assigned = $this->plant($orgA, 'A-01');
        $unassigned = $this->plant($orgA, 'A-02');
        $inspector->assignPlant($assigned);

        $gate = Gate::forUser($inspector);

        $this->assertTrue($gate->allows('view', $assigned));
        $this->assertFalse($gate->allows('view', $unassigned));
        $this->assertFalse($gate->allows('create', Plant::class));
        $this->assertFalse($gate->allows('update', $assigned));
        $this->assertFalse($gate->allows('delete', $assigned));
    }

    public function test_area_se_hereda_de_la_planta_asignada(): void
    {
        $orgA = $this->organization('ORG-A');
        $inspector = $this->user($orgA);
        app(TenantContext::class)->set($orgA->id);
        $inspectorRole = $this->role($orgA, (string) config('tenancy.roles.inspector'));
        $inspector->assignRole($inspectorRole);
        RolePermission::firstOrCreate([
            'role_id' => $inspectorRole->id,
            'organization_id' => $orgA->id,
            'permission' => Permission::PlantsView->value,
        ], [
            'id' => (string) Str::uuid7(),
        ]);

        $plant = $this->plant($orgA, 'A-01');
        $assignedArea = $this->area($plant, 'A-AREA-01');
        $otherArea = $this->area($this->plant($orgA, 'A-02'), 'A-AREA-02');
        $inspector->assignPlant($plant);

        $gate = Gate::forUser($inspector);

        $this->assertTrue($gate->allows('view', $assignedArea));
        $this->assertFalse($gate->allows('view', $otherArea));
    }

    public function test_area_asignada_directamente_es_accesible(): void
    {
        $orgA = $this->organization('ORG-A');
        $inspector = $this->user($orgA);
        app(TenantContext::class)->set($orgA->id);
        $inspectorRole = $this->role($orgA, (string) config('tenancy.roles.inspector'));
        $inspector->assignRole($inspectorRole);
        RolePermission::firstOrCreate([
            'role_id' => $inspectorRole->id,
            'organization_id' => $orgA->id,
            'permission' => Permission::PlantsView->value,
        ], [
            'id' => (string) Str::uuid7(),
        ]);

        $area = $this->area($this->plant($orgA, 'A-01'), 'A-AREA-01');
        $inspector->assignArea($area);

        $this->assertTrue(Gate::forUser($inspector)->allows('view', $area));
    }

    public function test_linea_se_hereda_del_area_o_de_la_planta_asignada(): void
    {
        $orgA = $this->organization('ORG-A');
        $inspector = $this->user($orgA);
        app(TenantContext::class)->set($orgA->id);
        $inspectorRole = $this->role($orgA, (string) config('tenancy.roles.inspector'));
        $inspector->assignRole($inspectorRole);
        RolePermission::firstOrCreate([
            'role_id' => $inspectorRole->id,
            'organization_id' => $orgA->id,
            'permission' => Permission::PlantsView->value,
        ], [
            'id' => (string) Str::uuid7(),
        ]);

        $assignedPlant = $this->plant($orgA, 'A-01');
        $areaOfAssignedPlant = $this->area($assignedPlant, 'A-AREA-01');

        $otherPlant = $this->plant($orgA, 'A-02');
        $areaAssignedDirectly = $this->area($otherPlant, 'A-AREA-02');

        $unassignedPlant = $this->plant($orgA, 'A-03');
        $unassignedArea = $this->area($unassignedPlant, 'A-AREA-03');

        $lineByPlant = $this->productionLine($areaOfAssignedPlant, 'A-LINE-01');
        $lineByArea = $this->productionLine($areaAssignedDirectly, 'A-LINE-02');
        $lineUnassigned = $this->productionLine($unassignedArea, 'A-LINE-03');

        $inspector->assignPlant($assignedPlant);
        $inspector->assignArea($areaAssignedDirectly);

        $gate = Gate::forUser($inspector);

        $this->assertTrue($gate->allows('view', $lineByPlant));
        $this->assertTrue($gate->allows('view', $lineByArea));
        $this->assertFalse($gate->allows('view', $lineUnassigned));
    }

    public function test_una_politica_deniega_modelos_de_otra_organizacion_aunque_el_usuario_sea_admin(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $adminA = $this->admin($orgA);

        app(TenantContext::class)->clear();

        $areaB = $this->area($this->plant($orgB, 'B-01'), 'B-AREA');
        $lineB = $this->productionLine($areaB, 'B-LINE');

        app(TenantContext::class)->set($orgA->id);

        $gate = Gate::forUser($adminA);

        $this->assertFalse($gate->allows('view', $areaB));
        $this->assertFalse($gate->allows('view', $lineB));
        $this->assertFalse($gate->allows('update', $areaB));
    }
}
