<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_no_autenticado_devuelve_401(): void
    {
        $this->getJson('/api/auth/organizations')
            ->assertUnauthorized();
    }

    public function test_usuario_con_una_membresia_activa_devuelve_su_organizacion(): void
    {
        $organization = Organization::factory()->create([
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        $user = User::factory()
            ->forOrganization($organization)
            ->create();

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $token = $user->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $organization->id])
            ->assertJsonFragment(['name' => $organization->name])
            ->assertJsonFragment(['code' => $organization->code]);
    }

    public function test_usuario_con_dos_membresias_activas_devuelve_ambas(): void
    {
        $orgA = Organization::factory()->create([
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        $orgB = Organization::factory()->create([
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        $user = User::factory()->create();

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $orgA->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $token = $user->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $orgA->id])
            ->assertJsonFragment(['id' => $orgB->id]);
    }

    public function test_membresia_revocada_no_aparece(): void
    {
        $organization = Organization::factory()->create([
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        $user = User::factory()
            ->forOrganization($organization)
            ->create();

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_REVOKED,
        ]);

        $token = $user->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_usuario_sin_membresias_devuelve_coleccion_vacia(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_organizacion_operadora_esta_excluida(): void
    {
        $operator = Organization::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Consultora INSPECTA',
            'code' => 'INSPECTA-OP',
            'type' => Organization::TYPE_OPERATOR,
            'status' => true,
        ]);

        $user = User::factory()->create();

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $operator->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $token = $user->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_organizacion_cliente_inactiva_esta_excluida(): void
    {
        $organization = Organization::factory()->create([
            'type' => Organization::TYPE_CLIENT,
            'status' => false,
        ]);

        $user = User::factory()
            ->forOrganization($organization)
            ->create();

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $token = $user->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_organizacion_eliminada_esta_excluida(): void
    {
        $organization = Organization::factory()->create([
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        $organization->delete();

        $user = User::factory()
            ->forOrganization($organization)
            ->create();

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $token = $user->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_organizacion_de_otro_usuario_no_aparece(): void
    {
        $organization = Organization::factory()->create([
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        $otherUser = User::factory()->create();

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $otherUser->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $user = User::factory()->create();

        $token = $user->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_no_requiere_cabecera_x_organization_id(): void
    {
        $organization = Organization::factory()->create([
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        $user = User::factory()
            ->forOrganization($organization)
            ->create();

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $token = $user->createToken('tests')->plainTextToken;

        // Sin cabecera X-Organization-Id
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk();
    }

    public function test_funciona_sin_tenant_context(): void
    {
        $organization = Organization::factory()->create([
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        $user = User::factory()
            ->forOrganization($organization)
            ->create();

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $token = $user->createToken('tests')->plainTextToken;

        // Asegurar que no hay TenantContext establecido
        app(TenantContext::class)->clear();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // El contexto no debe haberse establecido
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_no_usa_users_organization_id_como_fuente(): void
    {
        $orgA = Organization::factory()->create([
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        $orgB = Organization::factory()->create([
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        // Usuario con organization_id = orgA, pero membresía activa solo en orgB
        $user = User::factory()
            ->forOrganization($orgA)
            ->create();

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $token = $user->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $orgB->id])
            ->assertJsonMissing(['id' => $orgA->id]);
    }
}
