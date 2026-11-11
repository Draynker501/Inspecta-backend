<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class PermissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function setupUserWithRole(Organization $org, string $roleName = 'Administrador'): array
    {
        $role = $this->role($org, $roleName);
        $user = $this->user($org);

        app(TenantContext::class)->set($org->id);
        $user->assignRole($role);

        return [$user, $role];
    }

    public function test_permiso_concedido_devuelve_true_y_no_concedido_false(): void
    {
        $org = $this->clientOrganization('ORG-A');
        [$user, $role] = $this->setupUserWithRole($org);

        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        app(TenantContext::class)->set($org->id);

        $this->assertTrue($user->hasPermission(Permission::PlantsView));
        $this->assertFalse($user->hasPermission(Permission::PlantsManage));
    }

    public function test_denegacion_por_defecto_sin_filas_role_permissions(): void
    {
        $org = $this->clientOrganization('ORG-A');
        [$user, $role] = $this->setupUserWithRole($org);

        app(TenantContext::class)->set($org->id);

        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }

    public function test_usuario_con_roles_distintos_en_dos_orgs_no_filtra(): void
    {
        $orgA = $this->clientOrganization('ORG-A');
        $orgB = $this->clientOrganization('ORG-B');

        $roleA = $this->role($orgA, 'Administrador');
        $roleB = $this->role($orgB, 'Supervisor');

        $user = $this->user($orgA);
        // membresía en B
        OrganizationMembership::firstOrCreate(
            ['user_id' => $user->id, 'organization_id' => $orgB->id],
            ['id' => (string) Str::uuid7(), 'status' => OrganizationMembership::STATUS_ACTIVE],
        );

        app(TenantContext::class)->set($orgA->id);
        $user->assignRole($roleA);

        app(TenantContext::class)->set($orgB->id);
        $user->assignRole($roleB);

        // conceder PlantsView solo en org A
        app(TenantContext::class)->set($orgA->id);
        RolePermission::create([
            'role_id' => $roleA->id,
            'organization_id' => $orgA->id,
            'permission' => Permission::PlantsView->value,
        ]);

        app(TenantContext::class)->set($orgA->id);
        $this->assertTrue($user->hasPermission(Permission::PlantsView));

        app(TenantContext::class)->set($orgB->id);
        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }

    public function test_membresia_revocada_deniega(): void
    {
        $org = $this->clientOrganization('ORG-A');
        [$user, $role] = $this->setupUserWithRole($org);

        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        // revocar membresía
        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $org->id)
            ->first();
        $membership->status = OrganizationMembership::STATUS_REVOKED;
        $membership->save();

        app(TenantContext::class)->set($org->id);
        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }

    public function test_membresia_ausente_deniega(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $user = User::factory()->create();
        $role = $this->role($org, 'Administrador');

        // Conceder permiso al rol (sin membresía para el user).
        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        // Sin membresía activa, denegado aunque haya rol+permiso.
        app(TenantContext::class)->set($org->id);
        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }

    public function test_sin_contexto_fail_closed(): void
    {
        $org = $this->clientOrganization('ORG-A');
        [$user, $role] = $this->setupUserWithRole($org);

        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        app(TenantContext::class)->clear();
        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }

    public function test_permiso_desconocido_denegado(): void
    {
        $org = $this->clientOrganization('ORG-A');
        [$user, $role] = $this->setupUserWithRole($org);

        app(TenantContext::class)->set($org->id);
        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }

    public function test_permiso_desconocido_rechazado_al_crear(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $role = $this->role($org, 'Administrador');

        $this->expectException(InvalidArgumentException::class);
        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => 'no.existe',
        ]);
    }

    public function test_permiso_desconocido_rechazado_al_actualizar(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $role = $this->role($org, 'Administrador');

        $rp = RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $rp->permission = 'no.existe';
        $rp->save();
    }

    public function test_cache_no_se_filtra_entre_contextos_misma_instancia(): void
    {
        $orgA = $this->clientOrganization('ORG-A');
        $orgB = $this->clientOrganization('ORG-B');

        $roleA = $this->role($orgA, 'Administrador');
        $roleB = $this->role($orgB, 'Supervisor');

        $user = $this->user($orgA);
        OrganizationMembership::firstOrCreate(
            ['user_id' => $user->id, 'organization_id' => $orgB->id],
            ['id' => (string) Str::uuid7(), 'status' => OrganizationMembership::STATUS_ACTIVE],
        );

        app(TenantContext::class)->set($orgA->id);
        $user->assignRole($roleA);

        app(TenantContext::class)->set($orgB->id);
        $user->assignRole($roleB);

        app(TenantContext::class)->set($orgA->id);
        RolePermission::create([
            'role_id' => $roleA->id,
            'organization_id' => $orgA->id,
            'permission' => Permission::PlantsView->value,
        ]);

        // Primera comprobación en A (con la misma instancia).
        app(TenantContext::class)->set($orgA->id);
        $this->assertTrue($user->hasPermission(Permission::PlantsView));

        // Cambiar a B con la misma instancia: no debe filtrar.
        app(TenantContext::class)->set($orgB->id);
        $this->assertFalse($user->hasPermission(Permission::PlantsView));

        // Volver a A: sigue concedido (caché por org).
        app(TenantContext::class)->set($orgA->id);
        $this->assertTrue($user->hasPermission(Permission::PlantsView));
    }

    public function test_cache_refleja_cambio_de_permiso_del_rol_misma_instancia(): void
    {
        $org = $this->clientOrganization('ORG-A');
        [$user, $role] = $this->setupUserWithRole($org);

        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        app(TenantContext::class)->set($org->id);
        $this->assertTrue($user->hasPermission(Permission::PlantsView));

        // Eliminar el permiso del rol (evento invalida la caché).
        $rp = RolePermission::where('role_id', $role->id)
            ->where('organization_id', $org->id)
            ->where('permission', Permission::PlantsView->value)
            ->first();
        $rp->delete();

        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }

    public function test_cache_refleja_cambio_de_asignacion_de_rol_misma_instancia(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $role = $this->role($org, 'Administrador');
        $user = $this->user($org);

        app(TenantContext::class)->set($org->id);
        $user->assignRole($role);

        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        app(TenantContext::class)->set($org->id);
        $this->assertTrue($user->hasPermission(Permission::PlantsView));

        // Revocar la asignación del rol (evento invalida la caché).
        $ur = UserRole::where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->where('organization_id', $org->id)
            ->first();
        $ur->delete();

        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }

    public function test_cache_refleja_revocacion_de_membresia_misma_instancia(): void
    {
        $org = $this->clientOrganization('ORG-A');
        [$user, $role] = $this->setupUserWithRole($org);

        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        app(TenantContext::class)->set($org->id);
        $this->assertTrue($user->hasPermission(Permission::PlantsView));

        // Revocar membresía.
        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $org->id)
            ->first();
        $membership->status = OrganizationMembership::STATUS_REVOKED;
        $membership->save();

        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }

    public function test_permiso_no_se_concede_por_rol_ajeno_en_la_misma_org(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $roleConPermiso = $this->role($org, 'Administrador');
        $roleSinPermiso = $this->role($org, 'Supervisor');

        $user = $this->user($org);

        app(TenantContext::class)->set($org->id);
        $user->assignRole($roleSinPermiso);

        RolePermission::create([
            'role_id' => $roleConPermiso->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        app(TenantContext::class)->set($org->id);
        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }

    public function test_duplicado_role_permission_rechazado(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $role = $this->role($org, 'Administrador');

        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        $this->expectException(QueryException::class);
        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);
    }

    public function test_cache_refleja_actualizacion_de_role_id_misma_instancia(): void
    {
        $org = $this->clientOrganization('ORG-A');
        $roleConPermiso = $this->role($org, 'Administrador');
        $roleSinPermiso = $this->role($org, 'Supervisor');
        $user = $this->user($org);

        app(TenantContext::class)->set($org->id);
        $user->assignRole($roleConPermiso);

        RolePermission::create([
            'role_id' => $roleConPermiso->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        app(TenantContext::class)->set($org->id);
        $this->assertTrue($user->hasPermission(Permission::PlantsView));

        // Actualizar role_id del UserRole (evento updated invalida la caché).
        $ur = UserRole::where('user_id', $user->id)
            ->where('role_id', $roleConPermiso->id)
            ->where('organization_id', $org->id)
            ->first();
        $ur->role_id = $roleSinPermiso->id;
        $ur->save();

        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }

    public function test_cache_no_persiste_entre_peticiones_simuladas(): void
    {
        $org = $this->clientOrganization('ORG-A');
        [$user, $role] = $this->setupUserWithRole($org);

        RolePermission::create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::PlantsView->value,
        ]);

        // Primera petición simulada.
        app(TenantContext::class)->set($org->id);
        $this->assertTrue($user->hasPermission(Permission::PlantsView));

        // Revocar el permiso directamente (sin eventos) entre peticiones.
        DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->where('organization_id', $org->id)
            ->delete();

        // ResetTenantContext limpia la caché al final de la petición.
        app(TenantContext::class)->clear();

        // Segunda petición simulada: la caché se recarga (permiso revocado).
        app(TenantContext::class)->set($org->id);
        $this->assertFalse($user->hasPermission(Permission::PlantsView));
    }
}
