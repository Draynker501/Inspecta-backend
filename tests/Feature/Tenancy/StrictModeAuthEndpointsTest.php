<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\MissingTenantContextException;
use App\Models\Plant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class StrictModeAuthEndpointsTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private $orgA;

    private $orgB;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Datos preparados ANTES de activar el modo estricto sin exención.
        $this->orgA = $this->organization('ORG-A');
        $this->orgB = $this->organization('ORG-B');

        $this->user = $this->user($this->orgA);

        $this->plant($this->orgA, 'PLT-A1');
        $this->plant($this->orgA, 'PLT-A2');
        $this->plant($this->orgB, 'PLT-B1');
        $this->plant($this->orgB, 'PLT-B2');

        Route::prefix('api/strict-probe')->middleware('api.tenant')->group(function (): void {
            Route::get('plants', function () {
                return response()->json(['count' => Plant::count()]);
            });
        });

        Route::prefix('api/strict-probe-no-tenant')->group(function (): void {
            Route::get('plants', function () {
                return response()->json(['count' => Plant::count()]);
            });
        });
    }

    public function test_login_exitoso_bajo_modo_estricto(): void
    {
        config(['tenancy.strict_mode' => true, 'tenancy.allow_console_without_tenant' => false]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_me_sin_cabecera_bajo_modo_estricto(): void
    {
        config(['tenancy.strict_mode' => true, 'tenancy.allow_console_without_tenant' => false]);

        $token = $this->user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_organizations_lista_solo_org_a(): void
    {
        config(['tenancy.strict_mode' => true, 'tenancy.allow_console_without_tenant' => false]);

        $token = $this->user->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('ORG-A', $codes);
        $this->assertNotContains('ORG-B', $codes);
    }

    public function test_logout_bajo_modo_estricto(): void
    {
        config(['tenancy.strict_mode' => true, 'tenancy.allow_console_without_tenant' => false]);

        $token = $this->user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk();
    }

    public function test_ruta_con_tenant_sin_cabecera_devuelve_400(): void
    {
        config(['tenancy.strict_mode' => true, 'tenancy.allow_console_without_tenant' => false]);

        $token = $this->user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/strict-probe/plants')
            ->assertStatus(400);
    }

    public function test_ruta_con_tenant_con_cabecera_a_devuelve_200_conteo_2(): void
    {
        config(['tenancy.strict_mode' => true, 'tenancy.allow_console_without_tenant' => false]);

        $token = $this->user->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $this->orgA->id)
            ->getJson('/api/strict-probe/plants');

        $response->assertOk()
            ->assertJsonPath('count', 2);
    }

    public function test_ruta_con_tenant_con_cabecera_b_devuelve_403(): void
    {
        config(['tenancy.strict_mode' => true, 'tenancy.allow_console_without_tenant' => false]);

        $token = $this->user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $this->orgB->id)
            ->getJson('/api/strict-probe/plants')
            ->assertForbidden();
    }

    public function test_ruta_sin_tenant_lanza_missing_tenant_context_exception(): void
    {
        config(['tenancy.strict_mode' => true, 'tenancy.allow_console_without_tenant' => false]);

        $this->withoutExceptionHandling();

        $token = $this->user->createToken('t')->plainTextToken;

        $this->expectException(MissingTenantContextException::class);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/strict-probe-no-tenant/plants');
    }

    public function test_con_strict_false_responde_200_conteo_4(): void
    {
        config(['tenancy.strict_mode' => false, 'tenancy.allow_console_without_tenant' => false]);

        $token = $this->user->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/strict-probe-no-tenant/plants');

        $response->assertOk()
            ->assertJsonPath('count', 4);
    }
}
