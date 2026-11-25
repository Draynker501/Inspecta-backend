<?php

namespace Tests\Feature\Bootstrap;

use App\Authorization\ClientRoleProvisioner;
use App\Authorization\ClientRoleTemplate;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RolePermission;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientRoleProvisionerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Estructura oficial esperada, independiente de la implementacion.
     *
     * @return array<string, array{system_key: string, permissions: list<string>}>
     */
    private function expectedStructure(): array
    {
        return [
            'Administrador' => [
                'system_key' => 'admin',
                'permissions' => [
                    'checklists.manage',
                    'checklists.view',
                    'inspections.execute',
                    'inspections.manage',
                    'plants.view',
                ],
            ],
            'Inspector' => [
                'system_key' => 'inspector',
                'permissions' => ['checklists.view', 'inspections.execute', 'plants.view'],
            ],
            'Supervisor' => [
                'system_key' => 'supervisor',
                'permissions' => ['checklists.view', 'inspections.manage', 'plants.view'],
            ],
        ];
    }

    /**
     * @return array<string, array{system_key: ?string, permissions: list<string>}>
     */
    private function structureOf(Organization $organization): array
    {
        $structure = [];

        foreach (Role::query()->where('organization_id', $organization->id)->get() as $role) {
            $structure[$role->name] = [
                'system_key' => $role->system_key,
                'permissions' => RolePermission::query()
                    ->where('role_id', $role->id)
                    ->pluck('permission')
                    ->sort()
                    ->values()
                    ->all(),
            ];
        }

        return $structure;
    }

    public function test_el_seeder_y_el_provisioner_generan_la_misma_estructura(): void
    {
        $seeded = Organization::factory()->create([
            'code' => 'SEED-01',
            'type' => Organization::TYPE_CLIENT,
        ]);

        $this->seed(RoleSeeder::class);

        $provisioned = Organization::factory()->create([
            'code' => 'PROV-01',
            'type' => Organization::TYPE_CLIENT,
        ]);

        app(ClientRoleProvisioner::class)->provision($provisioned);

        $expected = $this->expectedStructure();

        foreach ([$seeded, $provisioned] as $organization) {
            $structure = $this->structureOf($organization);
            ksort($structure);
            $normalized = $expected;
            ksort($normalized);

            $this->assertSame($normalized, $structure);
        }
    }

    public function test_no_sobrescribe_un_rol_personalizado_con_el_mismo_nombre(): void
    {
        $organization = Organization::factory()->create([
            'code' => 'CUSTOM-01',
            'type' => Organization::TYPE_CLIENT,
        ]);

        $custom = Role::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'name' => ClientRoleTemplate::ADMIN,
            'description' => 'Rol personalizado del cliente',
            'status' => true,
        ]);

        $roles = app(ClientRoleProvisioner::class)->provision($organization);

        $this->assertSame($custom->id, $roles[ClientRoleTemplate::ADMIN]->id);
        $this->assertSame(0, RolePermission::query()->where('role_id', $custom->id)->count());
        $this->assertNull($custom->fresh()->system_key);
        $this->assertCount(3, $roles);
        $this->assertNotNull($roles['Inspector']->system_key);
        $this->assertNotNull($roles['Supervisor']->system_key);
    }

    public function test_los_ids_deterministas_dependen_de_la_organizacion(): void
    {
        $first = Organization::factory()->create(['code' => 'IDS-01', 'type' => Organization::TYPE_CLIENT]);
        $second = Organization::factory()->create(['code' => 'IDS-02', 'type' => Organization::TYPE_CLIENT]);

        $provisioner = app(ClientRoleProvisioner::class);
        $firstRoles = $provisioner->provision($first);
        $secondRoles = $provisioner->provision($second);

        $this->assertSame(
            ClientRoleTemplate::deterministicId($first->id, ClientRoleTemplate::ADMIN),
            $firstRoles[ClientRoleTemplate::ADMIN]->id,
        );

        $this->assertNotSame(
            $firstRoles[ClientRoleTemplate::ADMIN]->id,
            $secondRoles[ClientRoleTemplate::ADMIN]->id,
        );
    }
}
