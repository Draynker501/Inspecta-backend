<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ModuleCapabilitiesTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /**
     * Asignar un rol con permisos específicos dentro de una organización.
     */
    private function assignPermissions(
        User $user,
        Organization $organization,
        string $roleName,
        array $permissions
    ): void {
        app(TenantContext::class)->set($organization->id);

        $role = $this->role($organization, $roleName);

        $user->assignRole($role);

        foreach ($permissions as $permission) {
            RolePermission::firstOrCreate([
                'role_id' => $role->id,
                'organization_id' => $organization->id,
                'permission' => $permission->value,
            ], [
                'id' => (string) Str::uuid7(),
            ]);
        }

        app(TenantContext::class)->clear();
    }

    /**
     * Un usuario con permisos suficientes puede visualizar los módulos.
     */
    public function test_user_can_view_authorized_modules(): void
    {
        $organization = $this->clientOrganization('CAP-ADMIN');

        $user = $this->user($organization);

        $this->assignPermissions(
            $user,
            $organization,
            'Gestor integral de pruebas',
            [
                Permission::InspectionsManage,
                Permission::FindingsClose,
                Permission::CorrectiveActionsManage,
                Permission::ChecklistsView,
                Permission::NormsView,
                Permission::ReportsView,
                Permission::UsersManage,
            ]
        );

        $this->actingAsApiInOrganization($user, $organization)
            ->getJson('/api/auth/capabilities')
            ->assertOk()
            ->assertJsonPath(
                'organization_id',
                $organization->id
            )
            ->assertJsonPath('roles', [
                'Gestor integral de pruebas',
            ])
            ->assertJsonPath('modules', [
                'dashboard' => true,
                'inspections' => true,
                'incidents' => true,
                'corrective_actions' => true,
                'checklists' => true,
                'norms' => true,
                'reports' => true,
                'administration' => true,
            ])
            ->assertJsonPath('corrective_actions.view', true)
            ->assertJsonPath('corrective_actions.create', true)
            ->assertJsonPath('corrective_actions.update', true)
            ->assertJsonPath('corrective_actions.record_update', true)
            ->assertJsonPath('corrective_actions.verify', false);
    }

    /**
     * Un usuario sin permisos solamente puede visualizar el Dashboard.
     */
    public function test_user_without_permissions_has_limited_modules(): void
    {
        $organization = $this->clientOrganization('CAP-EMPTY');

        $user = $this->user($organization);

        $this->actingAsApiInOrganization($user, $organization)
            ->getJson('/api/auth/capabilities')
            ->assertOk()
            ->assertJsonPath('roles', [])
            ->assertJsonPath('modules', [
                'dashboard' => true,
                'inspections' => false,
                'incidents' => false,
                'corrective_actions' => false,
                'checklists' => false,
                'norms' => false,
                'reports' => false,
                'administration' => false,
            ])
            ->assertJsonPath('corrective_actions', [
                'view' => false,
                'create' => false,
                'update' => false,
                'record_update' => false,
                'verify' => false,
            ]);
    }

    /**
     * Los permisos de lectura permiten consultar módulos,
     * pero no conceden automáticamente permisos de escritura.
     */
    public function test_read_permissions_enable_only_expected_modules(): void
    {
        $organization = $this->clientOrganization('CAP-READ');

        $user = $this->user($organization);

        $this->assignPermissions(
            $user,
            $organization,
            'Consultor de lectura',
            [
                Permission::ChecklistsView,
                Permission::NormsView,
                Permission::ReportsViewOwn,
                Permission::FindingsRegister,
            ]
        );

        $this->actingAsApiInOrganization($user, $organization)
            ->getJson('/api/auth/capabilities')
            ->assertOk()
            ->assertJsonPath('modules', [
                'dashboard' => true,
                'inspections' => false,
                'incidents' => true,
                'corrective_actions' => true,
                'checklists' => true,
                'norms' => true,
                'reports' => true,
                'administration' => false,
            ])
            ->assertJsonPath('corrective_actions.view', true)
            ->assertJsonPath('corrective_actions.create', false)
            ->assertJsonPath('corrective_actions.update', false)
            ->assertJsonPath('corrective_actions.record_update', false)
            ->assertJsonPath('corrective_actions.verify', false);
    }

    /**
     * Los permisos de administración de catálogos no implican
     * automáticamente acceso a sus listados.
     */
    public function test_catalog_management_does_not_imply_read_permission(): void
    {
        $organization = $this->clientOrganization('CAP-WRITE');

        $user = $this->user($organization);

        $this->assignPermissions(
            $user,
            $organization,
            'Gestor sin lectura',
            [
                Permission::ChecklistsManage,
                Permission::NormsManage,
            ]
        );

        $this->actingAsApiInOrganization($user, $organization)
            ->getJson('/api/auth/capabilities')
            ->assertOk()
            ->assertJsonPath('modules.checklists', false)
            ->assertJsonPath('modules.norms', false);
    }

    /**
     * Un mismo usuario puede tener capacidades diferentes
     * dependiendo de la organización seleccionada.
     */
    public function test_capabilities_are_isolated_by_organization(): void
    {
        $organizationA = $this->clientOrganization('CAP-ORG-A');

        $organizationB = $this->clientOrganization('CAP-ORG-B');

        $user = $this->user($organizationA);

        OrganizationMembership::firstOrCreate([
            'user_id' => $user->id,
            'organization_id' => $organizationB->id,
        ], [
            'id' => (string) Str::uuid7(),
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->assignPermissions(
            $user,
            $organizationA,
            'Gestor organización A',
            [
                Permission::ChecklistsView,
                Permission::NormsView,
                Permission::UsersManage,
            ]
        );

        $this->assignPermissions(
            $user,
            $organizationB,
            'Inspector organización B',
            [
                Permission::InspectionsExecute,
            ]
        );

        $this->actingAsApiInOrganization($user, $organizationA)
            ->getJson('/api/auth/capabilities')
            ->assertOk()
            ->assertJsonPath(
                'organization_id',
                $organizationA->id
            )
            ->assertJsonPath('roles', [
                'Gestor organización A',
            ])
            ->assertJsonPath('modules.checklists', true)
            ->assertJsonPath('modules.norms', true)
            ->assertJsonPath('modules.administration', true)
            ->assertJsonPath('modules.inspections', false);

        $this->withHeader(
            'X-Organization-Id',
            $organizationB->id
        )
            ->getJson('/api/auth/capabilities')
            ->assertOk()
            ->assertJsonPath(
                'organization_id',
                $organizationB->id
            )
            ->assertJsonPath('roles', [
                'Inspector organización B',
            ])
            ->assertJsonPath('modules.checklists', false)
            ->assertJsonPath('modules.norms', false)
            ->assertJsonPath('modules.administration', false)
            ->assertJsonPath('modules.inspections', true);
    }

    /**
     * No se pueden consultar capacidades de una organización
     * a la que el usuario no pertenece.
     */
    public function test_foreign_organization_capabilities_are_denied(): void
    {
        $organizationA = $this->clientOrganization('CAP-OWN');

        $organizationB = $this->clientOrganization('CAP-FOREIGN');

        $user = $this->user($organizationA);

        $this->actingAsApiInOrganization($user, $organizationB)
            ->getJson('/api/auth/capabilities')
            ->assertForbidden();
    }

    /**
     * El endpoint requiere autenticación.
     */
    public function test_unauthenticated_user_cannot_access_capabilities(): void
    {
        $organization = $this->clientOrganization('CAP-AUTH');

        $this->withHeader(
            'X-Organization-Id',
            $organization->id
        )
            ->getJson('/api/auth/capabilities')
            ->assertUnauthorized();
    }
}
