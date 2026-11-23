<?php

namespace Tests\Feature\Auth;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class CapabilitiesTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private Organization $orgA;

    private Organization $orgB;

    private Organization $operator;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = $this->clientOrganization('CAP-A');
        $this->orgB = $this->clientOrganization('CAP-B');
        $this->operator = $this->operatorOrganization();
        $this->user = $this->user($this->orgA);
    }

    private function grant(
        User $user,
        Organization $organization,
        array $permissions,
        ?string $systemKey = null
    ): void {
        app(TenantContext::class)->runAs($organization->id, function () use ($user, $organization, $permissions, $systemKey): void {
            $role = $this->role($organization, 'Cap-'.Str::random(10));
            $user->assignRole($role);

            foreach ($permissions as $permission) {
                RolePermission::firstOrCreate(
                    [
                        'role_id' => $role->id,
                        'organization_id' => $organization->id,
                        'permission' => $permission->value,
                    ],
                    ['id' => (string) Str::uuid7()]
                );
            }

            if ($systemKey !== null) {
                DB::table('roles')->where('id', $role->id)->update(['system_key' => $systemKey]);
            }
        });
    }

    private function capabilities(User $user, Organization $organization)
    {
        return $this->actingAsApiInOrganization($user, $organization)
            ->getJson('/api/auth/capabilities');
    }

    public function test_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/auth/capabilities')->assertUnauthorized();
    }

    public function test_sin_cabecera_de_organizacion_devuelve_400(): void
    {
        $this->actingAsApi($this->user)
            ->getJson('/api/auth/capabilities')
            ->assertStatus(400)
            ->assertJsonPath('code', 'organization_required');
    }

    public function test_uuid_de_organizacion_invalido_devuelve_400(): void
    {
        $this->actingAsApi($this->user)
            ->withHeader('X-Organization-Id', 'no-es-uuid')
            ->getJson('/api/auth/capabilities')
            ->assertStatus(400)
            ->assertJsonPath('code', 'organization_invalid');
    }

    public function test_organizacion_ajena_devuelve_403_sin_filtrar_capacidades(): void
    {
        $this->capabilities($this->user, $this->orgB)
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_access_denied')
            ->assertJsonMissingPath('corrective_actions');
    }

    public function test_membresia_revocada_devuelve_403(): void
    {
        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->user->id,
            'organization_id' => $this->orgB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        OrganizationMembership::where('user_id', $this->user->id)
            ->where('organization_id', $this->orgB->id)
            ->update([
                'status' => OrganizationMembership::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);

        $this->capabilities($this->user, $this->orgB)
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_access_denied');
    }

    public function test_organizacion_operadora_no_tiene_acceso_implicito(): void
    {
        $operatorUser = $this->user($this->operator);

        $this->capabilities($operatorUser, $this->operator)
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_not_tenant');
    }

    public function test_usuario_sin_permisos_obtiene_todas_las_capacidades_en_false(): void
    {
        $response = $this->capabilities($this->user, $this->orgA);

        $response->assertOk()
            ->assertJsonPath('organization_id', $this->orgA->id)
            ->assertJsonPath('corrective_actions.view', false)
            ->assertJsonPath('corrective_actions.create', false)
            ->assertJsonPath('corrective_actions.update', false)
            ->assertJsonPath('corrective_actions.record_update', false)
            ->assertJsonPath('corrective_actions.verify', false)
            ->assertJsonMissingPath('permissions')
            ->assertJsonMissingPath('roles');
    }

    public function test_findings_register_solo_habilita_lectura(): void
    {
        $this->grant($this->user, $this->orgA, [Permission::FindingsRegister]);

        $this->capabilities($this->user, $this->orgA)
            ->assertOk()
            ->assertJsonPath('corrective_actions.view', true)
            ->assertJsonPath('corrective_actions.create', false)
            ->assertJsonPath('corrective_actions.update', false)
            ->assertJsonPath('corrective_actions.record_update', false)
            ->assertJsonPath('corrective_actions.verify', false);
    }

    public function test_findings_close_solo_habilita_lectura(): void
    {
        $this->grant($this->user, $this->orgA, [Permission::FindingsClose]);

        $this->capabilities($this->user, $this->orgA)
            ->assertOk()
            ->assertJsonPath('corrective_actions.view', true)
            ->assertJsonPath('corrective_actions.create', false)
            ->assertJsonPath('corrective_actions.update', false)
            ->assertJsonPath('corrective_actions.record_update', false)
            ->assertJsonPath('corrective_actions.verify', false);
    }

    public function test_manage_habilita_lectura_y_operaciones_generales_de_escritura(): void
    {
        $this->grant($this->user, $this->orgA, [Permission::CorrectiveActionsManage]);

        $this->capabilities($this->user, $this->orgA)
            ->assertOk()
            ->assertJsonPath('corrective_actions.view', true)
            ->assertJsonPath('corrective_actions.create', true)
            ->assertJsonPath('corrective_actions.update', true)
            ->assertJsonPath('corrective_actions.record_update', true)
            ->assertJsonPath('corrective_actions.verify', false)
            ->assertJsonMissingPath('permissions')
            ->assertJsonMissingPath('roles');
    }

    public function test_verify_es_independiente_de_manage_y_habilita_solo_verificacion(): void
    {
        $this->grant($this->user, $this->orgA, [
            Permission::FindingsRegister,
            Permission::CorrectiveActionsVerify,
        ], 'supervisor');

        $this->capabilities($this->user, $this->orgA)
            ->assertOk()
            ->assertJsonPath('corrective_actions.view', true)
            ->assertJsonPath('corrective_actions.create', false)
            ->assertJsonPath('corrective_actions.update', false)
            ->assertJsonPath('corrective_actions.record_update', false)
            ->assertJsonPath('corrective_actions.verify', true);
    }

    public function test_verify_requiere_rol_base_elegible(): void
    {
        $this->grant($this->user, $this->orgA, [
            Permission::FindingsRegister,
            Permission::CorrectiveActionsVerify,
        ]);

        $this->capabilities($this->user, $this->orgA)
            ->assertOk()
            ->assertJsonPath('corrective_actions.verify', false);

        $this->grant($this->user, $this->orgA, [], 'inspector');
        $this->capabilities($this->user, $this->orgA)
            ->assertOk()
            ->assertJsonPath('corrective_actions.verify', false);

        $this->grant($this->user, $this->orgA, [], 'supervisor');
        $this->capabilities($this->user, $this->orgA)
            ->assertOk()
            ->assertJsonPath('corrective_actions.verify', true);
    }

    public function test_cambio_de_tenant_recalcula_capacidades_sin_filtrar_la_otra_org(): void
    {
        $this->grant($this->user, $this->orgA, [Permission::CorrectiveActionsManage]);

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->user->id,
            'organization_id' => $this->orgB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);
        $this->grant($this->user, $this->orgB, [Permission::FindingsRegister]);

        $this->capabilities($this->user, $this->orgA)
            ->assertOk()
            ->assertJsonPath('organization_id', $this->orgA->id)
            ->assertJsonPath('corrective_actions.create', true)
            ->assertJsonPath('corrective_actions.record_update', true);

        $response = $this->capabilities($this->user, $this->orgB)
            ->assertOk()
            ->assertJsonPath('organization_id', $this->orgB->id)
            ->assertJsonPath('corrective_actions.view', true)
            ->assertJsonPath('corrective_actions.create', false)
            ->assertJsonPath('corrective_actions.update', false)
            ->assertJsonPath('corrective_actions.record_update', false)
            ->assertJsonPath('corrective_actions.verify', false);

        $this->assertStringNotContainsString($this->orgA->id, $response->getContent());
    }
}
