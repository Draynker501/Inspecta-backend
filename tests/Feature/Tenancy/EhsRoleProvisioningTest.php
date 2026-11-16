<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class EhsRoleProvisioningTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_solo_roles_nuevos_reciben_plantilla_y_repetir_preserva_personalizaciones(): void
    {
        $client = $this->organization('EHS-SEED');
        $second = $this->organization('EHS-SEED-2');
        $operator = $this->operatorOrganization();
        $existing = $this->role($client, 'Supervisor');
        $existing->update(['description' => 'personalizado', 'status' => false]);
        RolePermission::create([
            'id' => (string) Str::uuid7(),
            'role_id' => $existing->id,
            'organization_id' => $client->id,
            'permission' => Permission::NormsManage->value,
        ]);
        $custom = $this->role($client, 'Personalizado');
        $this->seed(RoleSeeder::class);

        $admin = Role::where('organization_id', $client->id)->where('name', 'Administrador')->firstOrFail();
        $inspector = Role::where('organization_id', $client->id)->where('name', 'Inspector')->firstOrFail();
        $platform = Role::where('organization_id', $operator->id)->firstOrFail();
        $supervisor = Role::where('organization_id', $second->id)->where('name', 'Supervisor')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [Permission::ChecklistsView->value, Permission::ChecklistsManage->value, Permission::InspectionsExecute->value, Permission::InspectionsManage->value],
            $this->permissions($admin),
        );
        $this->assertEqualsCanonicalizing([Permission::ChecklistsView->value, Permission::InspectionsExecute->value], $this->permissions($inspector));
        $this->assertEqualsCanonicalizing([Permission::ChecklistsView->value, Permission::InspectionsManage->value], $this->permissions($supervisor));
        $this->assertSame([Permission::NormsManage->value], $this->permissions($existing));
        $this->assertSame([], $this->permissions($custom));
        $this->assertSame([], $this->permissions($platform));
        $this->assertSame('personalizado', $existing->fresh()->description);
        $this->assertFalse($existing->fresh()->status);

        RolePermission::where('role_id', $admin->id)
            ->where('permission', Permission::ChecklistsManage->value)->delete();
        $this->seed(RoleSeeder::class);

        $this->assertEqualsCanonicalizing([Permission::ChecklistsView->value, Permission::InspectionsExecute->value, Permission::InspectionsManage->value], $this->permissions($admin));
        $this->assertEqualsCanonicalizing([Permission::ChecklistsView->value, Permission::InspectionsExecute->value], $this->permissions($inspector));
        $this->assertSame([Permission::NormsManage->value], $this->permissions($existing));
        $this->assertSame('personalizado', $existing->fresh()->description);
        $this->assertFalse($existing->fresh()->status);
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_fallo_de_permiso_revierte_la_creacion_de_roles(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('El disparador de fallo es específico de SQLite.');
        }

        $client = $this->organization('EHS-ROLLBACK');
        DB::unprepared("CREATE TRIGGER reject_ehs_grants BEFORE INSERT ON role_permissions BEGIN SELECT RAISE(ABORT, 'grant failed'); END");

        try {
            $this->seed(RoleSeeder::class);
            $this->fail('El seeder debió fallar.');
        } catch (QueryException) {
            $this->assertSame(0, Role::where('organization_id', $client->id)->count());
            $this->assertSame(0, RolePermission::count());
        }
    }

    public function test_seeder_rechaza_contexto_de_tenant(): void
    {
        $client = $this->organization('EHS-CONTEXT');
        app(TenantContext::class)->set($client->id);

        try {
            $this->seed(RoleSeeder::class);
            $this->fail('El seeder debió rechazar el contexto.');
        } catch (LogicException) {
            $this->assertSame(0, Role::where('organization_id', $client->id)->count());
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    public function test_rol_de_plataforma_conserva_actualizacion_previa_y_permisos(): void
    {
        $operator = $this->operatorOrganization();
        $this->seed(RoleSeeder::class);
        $platform = Role::where('organization_id', $operator->id)->firstOrFail();
        $platform->update(['description' => 'cambiada', 'status' => false]);
        RolePermission::create([
            'id' => (string) Str::uuid7(),
            'role_id' => $platform->id,
            'organization_id' => $operator->id,
            'permission' => Permission::NormsView->value,
        ]);

        $this->seed(RoleSeeder::class);

        $this->assertTrue($platform->fresh()->status);
        $this->assertSame('Administración multiempresa de la consultora', $platform->fresh()->description);
        $this->assertSame([Permission::NormsView->value], $this->permissions($platform));
    }

    private function permissions(Role $role): array
    {
        return RolePermission::where('role_id', $role->id)->pluck('permission')->all();
    }
}
