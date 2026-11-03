<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class OrganizationSelectionTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/resolve-tenant-test')
            ->middleware('api.tenant')
            ->group(function (): void {
                Route::get('ping', function () {
                    return response()->json([
                        'message' => 'ok',
                        'organization_id' => app(TenantContext::class)->id(),
                    ]);
                });
            });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_usuario_con_membresia_en_operador_y_cliente_selecciona_cliente(): void
    {
        $orgClient = $this->organization('ORG-A');
        $orgOperator = Organization::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Consultora INSPECTA',
            'code' => 'INSPECTA-OP',
            'type' => Organization::TYPE_OPERATOR,
            'status' => true,
        ]);

        $user = $this->user($orgClient);

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $orgOperator->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $orgClient->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertOk()
            ->assertJsonPath('organization_id', $orgClient->id);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $orgOperator->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertForbidden()
            ->assertJsonPath('message', 'Tipo de organización no permitido.');
    }

    public function test_usuario_sin_membresia_no_puede_seleccionar_organizacion(): void
    {
        $organization = $this->organization('ORG-NO-MEMBERSHIP');
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertForbidden()
            ->assertJsonPath('message', 'No tiene membresía en esta organización.');
    }

    public function test_membresia_revocada_no_permite_seleccionar_organizacion(): void
    {
        $organization = $this->organization('ORG-REVOKED');
        $user = $this->user($organization);

        OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->update(['status' => OrganizationMembership::STATUS_REVOKED]);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertForbidden()
            ->assertJsonPath('message', 'La membresía no está activa.');
    }

    public function test_organizacion_inactiva_no_puede_seleccionarse(): void
    {
        $organization = $this->organization('ORG-INACTIVE', [
            'status' => false,
        ]);
        $user = $this->user($organization);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'La organización está inactiva. Contacte al administrador.'
            );
    }

    public function test_organizacion_eliminada_no_puede_seleccionarse(): void
    {
        $organization = $this->organization('ORG-DELETED');
        $user = $this->user($organization);

        $organization->delete();

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertForbidden()
            ->assertJsonPath('message', 'La organización ha sido eliminada.');
    }

    public function test_cabecera_organization_id_es_obligatoria(): void
    {
        $organization = $this->organization('ORG-NO-HEADER');
        $user = $this->user($organization);

        $this->actingAsApi($user)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertStatus(400)
            ->assertJsonPath(
                'message',
                'Cabecera X-Organization-Id requerida.'
            );
    }

    public function test_identificador_de_organizacion_debe_ser_uuid(): void
    {
        $organization = $this->organization('ORG-INVALID-UUID');
        $user = $this->user($organization);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', 'no-es-un-uuid')
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertStatus(400)
            ->assertJsonPath(
                'message',
                'El identificador de organización no es un UUID válido.'
            );
    }

    public function test_organizacion_inexistente_no_puede_seleccionarse(): void
    {

        $this->actingAsApi(User::factory()->create())
            ->withHeader('X-Organization-Id', (string) Str::uuid7())
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'La organización no existe o no está autorizada.'
            );
    }
}
