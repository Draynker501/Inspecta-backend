<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * Pruebas del middleware ResolveTenantContext.
 *
 * Las rutas de prueba se registran bajo el grupo api.tenant para ejercitar
 * el middleware exactamente como lo harían los endpoints de dominio (M2+).
 */
class ResolveTenantContextTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/resolve-tenant-test')->middleware('api.tenant')->group(function (): void {
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

    public function test_header_valido_y_membresia_activa_establece_contexto(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertOk()
            ->assertJsonPath('organization_id', $organization->id);
    }

    public function test_cabecera_ausente_devuelve_400(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        $this->actingAsApi($user)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertStatus(400)
            ->assertJsonPath('message', 'Cabecera X-Organization-Id requerida.');
    }

    public function test_uuid_invalido_devuelve_400(): void
    {
        $user = $this->user($this->organization('ORG-A'));

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', 'no-es-un-uuid')
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertStatus(400)
            ->assertJsonPath('message', 'El identificador de organización no es un UUID válido.');
    }

    public function test_organizacion_inexistente_devuelve_403(): void
    {
        $user = $this->user($this->organization('ORG-A'));

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', (string) Str::uuid7())
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertStatus(403)
            ->assertJsonPath('message', 'La organización no existe o no está autorizada.');
    }

    public function test_organizacion_inactiva_devuelve_403(): void
    {
        $organization = $this->organization('ORG-A', ['status' => false]);
        $user = $this->user($organization);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertStatus(403)
            ->assertJsonPath('message', 'La organización está inactiva. Contacte al administrador.');
    }

    public function test_organizacion_eliminada_logicamente_devuelve_403(): void
    {
        $organization = $this->organization('ORG-A');
        $organization->delete();
        $user = $this->user($organization);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertStatus(403)
            ->assertJsonPath('message', 'La organización ha sido eliminada.');
    }

    public function test_organizacion_tipo_no_client_devuelve_403(): void
    {
        $organization = Organization::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Consultora INSPECTA',
            'code' => 'INSPECTA-OP',
            'type' => Organization::TYPE_OPERATOR,
            'status' => true,
        ]);
        $user = $this->user($organization);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tipo de organización no permitido.');
    }

    public function test_membresia_inexistente_devuelve_403(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        // El usuario pertenece a orgA, pero solicita orgB sin membresía.
        $user = $this->user($orgA);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $orgB->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tiene membresía en esta organización.');
    }

    public function test_membresia_inactiva_devuelve_403(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        // Revocar la membresía automática creada por el factory.
        $user->memberships()->first()->update([
            'status' => OrganizationMembership::STATUS_REVOKED,
        ]);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertStatus(403)
            ->assertJsonPath('message', 'La membresía no está activa.');
    }

    public function test_usuario_no_autenticado_devuelve_401(): void
    {
        $this->getJson('/api/resolve-tenant-test/ping')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'No autenticado. Token de acceso ausente.');
    }

    public function test_consultor_con_dos_membresias_activas_seleccion_independiente(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        // Segunda membresía activa en otra organización.
        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        // Selección de orgA.
        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $orgA->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertOk()
            ->assertJsonPath('organization_id', $orgA->id);

        // Selección de orgB (independiente, misma petición de autenticación).
        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $orgB->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertOk()
            ->assertJsonPath('organization_id', $orgB->id);
    }

    public function test_no_hay_fallback_a_users_organization_id(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        // users.organization_id apunta a orgA, pero la membresía activa es en orgB.
        $user->memberships()->first()->update([
            'status' => OrganizationMembership::STATUS_REVOKED,
        ]);
        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        // Solicitar orgA (valor de users.organization_id) sin membresía activa → 403.
        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $orgA->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertStatus(403);

        // Solicitar orgB (membresía activa) → 200.
        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $orgB->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertOk()
            ->assertJsonPath('organization_id', $orgB->id);
    }
}
