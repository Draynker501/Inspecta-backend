<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plant;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class PlantsIndexTest extends TestCase
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

    public function test_sin_token_devuelve_401(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $this->withHeader('X-Organization-Id', $org->id)
            ->getJson('/api/plants')
            ->assertUnauthorized();
    }

    public function test_sin_cabecera_organizacional_devuelve_error_contexto(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $token = $user->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/plants')
            ->assertStatus(400); // organization_required
    }

    public function test_admin_con_permisos_lista_todas_las_plantas(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Administrador', [
            Permission::PlantsView->value,
            Permission::PlantsManage->value,
            Permission::LocationsViewAll->value,
        ]);
        $p1 = $this->plant($org, 'A');
        $p2 = $this->plant($org, 'B');

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_inspector_solo_ve_plantas_asignadas(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $assigned = $this->plant($org, 'A');
        $unassigned = $this->plant($org, 'B');
        app(TenantContext::class)->set($org->id);
        $user->assignPlant($assigned);

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants')
            ->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$assigned->id], $ids);
    }

    public function test_usuario_sin_plants_view_recibe_403(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', []);
        $this->plant($org, 'A');

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants')
            ->assertForbidden();
    }

    public function test_locations_view_all_sin_plants_view_recibe_403(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::LocationsViewAll->value]);
        $this->plant($org, 'A');

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants')
            ->assertForbidden();
    }

    public function test_usuario_solo_areas_asignadas_ve_coleccion_vacia(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);
        $plant = $this->plant($org, 'A');
        $area = $this->area($plant, 'AR');
        app(TenantContext::class)->set($org->id);
        $user->assignArea($area);

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_plantas_de_otra_organizacion_no_aparecen(): void
    {
        $orgA = $this->clientOrganization('ORG-A');
        $orgB = $this->clientOrganization('ORG-B');
        $user = $this->user($orgA);
        $this->grant($user, $orgA, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);
        $this->plant($orgA, 'A');
        app(TenantContext::class)->runAs($orgB->id, fn () => $this->plant($orgB, 'B'));

        $response = $this->actingAsApiInOrganization($user, $orgA)
            ->getJson('/api/plants')
            ->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_soft_deleted_no_aparece(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);
        $plant = $this->plant($org, 'A');
        $plant->delete();

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_paginacion_y_orden(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);
        foreach (['Zeta', 'Alpha'] as $name) {
            $p = $this->plant($org, $name);
            $p->name = $name;
            $p->save();
        }

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants?per_page=1&page=1')
            ->assertOk();
        $this->assertSame('Alpha', $response->json('data.0.name'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function test_parametros_invalidos_devuelven_422(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants?per_page=0')
            ->assertStatus(422);
    }

    public function test_revocacion_permiso_entre_peticiones(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $role = $this->grant($user, $org, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);
        $this->plant($org, 'A');
        $rp = RolePermission::where('role_id', $role->id)
            ->where('permission', Permission::PlantsView->value)
            ->first();

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants')
            ->assertOk();

        $rp->delete();

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants')
            ->assertForbidden();
    }

    public function test_token_invalido_devuelve_401(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $this->withHeader('Authorization', 'Bearer token-invalido')
            ->withHeader('X-Organization-Id', $org->id)
            ->getJson('/api/plants')
            ->assertUnauthorized();
    }

    public function test_organizacion_inactiva_deniega_contexto(): void
    {
        $org = $this->clientOrganization('ORG-A', ['status' => false]);
        $user = $this->user($org);
        $this->grant($user, $org, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);
        $this->plant($org, 'A');

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants')
            ->assertForbidden();
    }

    public function test_membresia_revocada_deniega_contexto(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);
        $this->plant($org, 'A');

        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $org->id)
            ->first();
        $membership->status = OrganizationMembership::STATUS_REVOKED;
        $membership->save();

        $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants')
            ->assertForbidden();
    }

    public function test_cambio_de_organizacion_no_contamina_resultados(): void
    {
        $orgA = $this->clientOrganization('ORG-A');
        $orgB = $this->clientOrganization('ORG-B');

        $user = $this->user($orgA);
        OrganizationMembership::firstOrCreate(
            ['user_id' => $user->id, 'organization_id' => $orgB->id],
            ['id' => (string) Str::uuid7(), 'status' => OrganizationMembership::STATUS_ACTIVE],
        );

        $this->grant($user, $orgA, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);
        $this->grant($user, $orgB, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);

        $plantA = app(TenantContext::class)->runAs($orgA->id, fn () => $this->plant($orgA, 'PA'));
        app(TenantContext::class)->runAs($orgB->id, fn () => $this->plant($orgB, 'PB'));

        $responseA = $this->actingAsApiInOrganization($user, $orgA)
            ->getJson('/api/plants')
            ->assertOk();
        $this->assertSame([$plantA->id], collect($responseA->json('data'))->pluck('id')->all());

        $responseB = $this->actingAsApiInOrganization($user, $orgB)
            ->getJson('/api/plants')
            ->assertOk();
        $plantBId = Plant::where('organization_id', $orgB->id)->value('id');
        $this->assertSame([$plantBId], collect($responseB->json('data'))->pluck('id')->all());
    }

    public function test_asignaciones_multiples_no_generan_duplicados(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = $this->user($org);
        $this->grant($user, $org, 'Inspector', [Permission::PlantsView->value]);

        $p1 = $this->plant($org, 'P1');
        $p2 = $this->plant($org, 'P2');
        app(TenantContext::class)->set($org->id);
        $user->assignPlant($p1);
        $user->assignPlant($p2);

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/plants')
            ->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertCount(2, $ids);
        $this->assertCount(2, array_unique($ids));
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_metadatos_cross_tenant_excluyen_recursos_no_autorizados(): void
    {
        $orgA = $this->clientOrganization('ORG-A');
        $orgB = $this->clientOrganization('ORG-B');
        $user = $this->user($orgA);
        $this->grant($user, $orgA, 'Administrador', [
            Permission::PlantsView->value,
            Permission::LocationsViewAll->value,
        ]);

        $this->plant($orgA, 'A1');
        $this->plant($orgA, 'A2');
        app(TenantContext::class)->runAs($orgB->id, fn () => $this->plant($orgB, 'B1'));

        $response = $this->actingAsApiInOrganization($user, $orgA)
            ->getJson('/api/plants?per_page=1')
            ->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertCount(1, $response->json('data'));
    }
}
