<?php

namespace Tests\Unit\Policies;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * Pruebas de PlatformOperatorPolicy en rojo (P3.3.3).
 *
 * La policy y User::isPlatformOperator() aún no están implementadas:
 * los casos "permitido" deben fallar hasta que se implementen.
 */
class PlatformOperatorPolicyTest extends TestCase
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
                    return response()->json(['message' => 'ok']);
                });
            });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public static function abilities(): array
    {
        return [
            'viewMemberships' => ['viewMemberships'],
            'manageMemberships' => ['manageMemberships'],
        ];
    }

    private function makeOperatorUser(
        Organization $operatorOrg,
        bool $withRole = true,
        bool $activeMembership = true,
    ): User {
        $user = $this->user($operatorOrg);

        if (! $activeMembership) {
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $operatorOrg->id)
                ->update(['status' => OrganizationMembership::STATUS_REVOKED]);
        }

        if ($withRole) {
            app(TenantContext::class)->set($operatorOrg->id);

            $role = $this->role(
                $operatorOrg,
                (string) config('tenancy.roles.platform_admin'),
            );

            $user->assignRole($role);

            app(TenantContext::class)->clear();
        }

        return $user;
    }

    // ---------- Permitidos ----------

    #[DataProvider('abilities')]
    public function test_permitido_operador_con_membresia_activa(string $ability): void
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->makeOperatorUser($operatorOrg, true);

        app(TenantContext::class)->clear();

        $this->assertTrue(Gate::forUser($user)->allows($ability));
    }

    #[DataProvider('abilities')]
    public function test_permitido_usuario_mixto_con_contexto_cliente_activo(string $ability): void
    {
        $operatorOrg = $this->operatorOrganization();
        $clientOrg = $this->clientOrganization('ORG-CLIENT');

        $user = $this->makeOperatorUser($operatorOrg, true);

        OrganizationMembership::firstOrCreate(
            ['user_id' => $user->id, 'organization_id' => $clientOrg->id],
            [
                'id' => (string) Str::uuid7(),
                'status' => OrganizationMembership::STATUS_ACTIVE,
            ],
        );

        app(TenantContext::class)->set($clientOrg->id);

        $this->assertTrue(Gate::forUser($user)->allows($ability));
    }

    // ---------- Denegados ----------

    #[DataProvider('abilities')]
    public function test_denegado_membresia_revocada(string $ability): void
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->makeOperatorUser($operatorOrg, true, true);

        OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $operatorOrg->id)
            ->update(['status' => OrganizationMembership::STATUS_REVOKED]);

        $this->assertFalse(Gate::forUser($user)->allows($ability));
    }

    #[DataProvider('abilities')]
    public function test_denegado_solo_cliente(string $ability): void
    {
        $clientOrg = $this->clientOrganization('ORG-ONLY-CLIENT');
        $user = $this->user($clientOrg);

        $this->assertFalse(Gate::forUser($user)->allows($ability));
    }

    #[DataProvider('abilities')]
    public function test_denegado_sin_membresias(string $ability): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows($ability));
    }

    #[DataProvider('abilities')]
    public function test_denegado_usuario_inactivo(string $ability): void
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->makeOperatorUser($operatorOrg, true);

        $user->status = false;
        $user->save();

        $this->assertFalse(Gate::forUser($user)->allows($ability));
    }

    #[DataProvider('abilities')]
    public function test_denegado_anonimo(string $ability): void
    {
        $this->operatorOrganization();

        $this->assertFalse(Gate::allows($ability));
    }

    #[DataProvider('abilities')]
    public function test_denegado_operadora_inactiva(string $ability): void
    {
        $operatorOrg = $this->operatorOrganization(['status' => false]);
        $user = $this->user($operatorOrg);

        $this->assertFalse(Gate::forUser($user)->allows($ability));
    }

    #[DataProvider('abilities')]
    public function test_denegado_operadora_eliminada(string $ability): void
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->user($operatorOrg);

        $operatorOrg->delete();

        $this->assertFalse(Gate::forUser($user)->allows($ability));
    }

    #[DataProvider('abilities')]
    public function test_denegado_tipo_desconocido(string $ability): void
    {
        $operatorOrg = $this->operatorOrganization(['type' => 'unknown']);
        $user = $this->user($operatorOrg);

        $this->assertFalse(Gate::forUser($user)->allows($ability));
    }

    #[DataProvider('abilities')]
    public function test_denegado_cero_operadoras(string $ability): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows($ability));
    }

    #[DataProvider('abilities')]
    public function test_denegado_dos_o_mas_operadoras(string $ability): void
    {
        $operatorOrgA = $this->operatorOrganization();
        $this->operatorOrganization([
            'code' => 'INSPECTA-OP-2',
            'name' => 'Consultora INSPECTA 2',
        ]);

        $user = $this->makeOperatorUser($operatorOrgA, true);

        $this->assertFalse(Gate::forUser($user)->allows($ability));
    }

    // ---------- Invariantes ----------

    #[DataProvider('abilities')]
    public function test_invariante_cliente_con_organization_id_operadora_denegado(string $ability): void
    {
        $operatorOrg = $this->operatorOrganization();
        $clientOrg = $this->clientOrganization('ORG-INV-CLIENT');

        $user = $this->user($clientOrg);
        $user->organization_id = $operatorOrg->id;
        $user->save();

        $this->assertFalse(Gate::forUser($user)->allows($ability));
    }

    #[DataProvider('abilities')]
    public function test_invariante_operador_con_organization_id_cliente_permitido(string $ability): void
    {
        $operatorOrg = $this->operatorOrganization();
        $clientOrg = $this->clientOrganization('ORG-INV-CLIENT-2');

        $user = $this->makeOperatorUser($operatorOrg, true);
        $user->organization_id = $clientOrg->id;
        $user->save();

        $this->assertTrue(Gate::forUser($user)->allows($ability));
    }

    public function test_invariante_operador_con_organization_id_nulo_permitido(): void
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->makeOperatorUser($operatorOrg, true);

        // In-memory only: la columna es NOT NULL en BD.
        $user->organization_id = null;

        $this->assertTrue(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertTrue(Gate::forUser($user)->allows('manageMemberships'));
    }

    #[DataProvider('abilities')]
    public function test_d07_user_role_con_organization_id_incoherente_denegado(string $ability): void
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->makeOperatorUser($operatorOrg, true);

        OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $operatorOrg->id)
            ->update(['status' => OrganizationMembership::STATUS_REVOKED]);

        $this->assertFalse(Gate::forUser($user)->allows($ability));
    }

    // ---------- withoutTenant ----------

    public function test_without_tenant_restaura_contexto_tras_evaluacion(): void
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->makeOperatorUser($operatorOrg, true);

        $clientOrg = $this->clientOrganization('ORG-CTX-CLIENT');
        app(TenantContext::class)->set($clientOrg->id);

        $before = app(TenantContext::class)->id();

        // User::isPlatformOperator() se implementa en el siguiente paso.
        $user->isPlatformOperator();

        $this->assertSame($before, app(TenantContext::class)->id());
    }

    public function test_membresia_activa_sin_rol_view_permitido_manage_denegado(): void
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->makeOperatorUser($operatorOrg, false);

        app(TenantContext::class)->clear();

        $this->assertTrue(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertFalse(Gate::forUser($user)->allows('manageMemberships'));
    }

    public function test_manage_denegado_si_nombre_rol_configurado_vacio(): void
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->makeOperatorUser($operatorOrg, false);

        app(TenantContext::class)->set($operatorOrg->id);

        $role = $this->role($operatorOrg, '');

        $user->assignRole($role);

        app(TenantContext::class)->clear();

        config(['tenancy.roles.platform_admin' => '']);

        $this->assertFalse(Gate::forUser($user)->allows('manageMemberships'));
    }

    public function test_manage_denegado_rol_plataforma_en_organizacion_cliente(): void
    {
        $clientOrg = $this->clientOrganization('ORG-PLAT-CLIENT');
        $user = $this->user($clientOrg);

        app(TenantContext::class)->set($clientOrg->id);

        $role = $this->role(
            $clientOrg,
            (string) config('tenancy.roles.platform_admin'),
        );

        $user->assignRole($role);

        app(TenantContext::class)->clear();

        $this->assertFalse(Gate::forUser($user)->allows('manageMemberships'));
    }

    public function test_without_tenant_restaura_contexto_si_lanza_excepcion(): void
    {
        $clientOrg = $this->clientOrganization('ORG-EXC-CLIENT');

        app(TenantContext::class)->set($clientOrg->id);

        $before = app(TenantContext::class)->id();

        try {
            app(TenantContext::class)->withoutTenant('platform-role-check', function () {
                throw new \RuntimeException('boom');
            });

            $this->fail('Se esperaba una excepción.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($before, app(TenantContext::class)->id());
        $this->assertTrue(app(TenantContext::class)->has());
    }

    // ---------- Regresión middleware ----------

    public function test_x_organization_id_operadora_sigue_devolviendo_403(): void
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->user($operatorOrg);

        $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $operatorOrg->id)
            ->getJson('/api/resolve-tenant-test/ping')
            ->assertForbidden()
            ->assertJsonPath('message', 'Tipo de organización no permitido.');
    }
}
