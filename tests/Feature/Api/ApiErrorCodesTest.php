<?php

namespace Tests\Feature\Api;

use App\Exceptions\CrossTenantOperationException;
use App\Models\Organization;
use App\Models\Plant;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiErrorResponse;
use App\Support\Tenancy\TenantErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * Sobre de error uniforme de la API (D-31b, D-31c).
 *
 * Contrato: { message: string, code: string, errors?: object } con la
 * regla de que el mismo código produce siempre el mismo mensaje.
 */
class ApiErrorCodesTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/error-probe')->middleware('api.tenant')->group(function (): void {
            Route::get('ping', function () {
                return response()->json(['pong' => true]);
            });

            Route::get('cross-tenant', function () {
                throw CrossTenantOperationException::relation('plant_id');
            });

            Route::get('plants/{plant}', function (Plant $plant) {
                return response()->json(['data' => ['id' => $plant->id]]);
            });

            Route::get('forbidden', function () {
                abort(403, 'mensaje personalizado que no debe aparecer');
            });

            Route::get('authz', function () {
                throw new AuthorizationException('otro mensaje secreto');
            });
        });
    }

    private function assertEnvelope($response, int $status, string $code, string $message): void
    {
        $response->assertStatus($status)
            ->assertJsonStructure(['message', 'code'])
            ->assertJsonPath('code', $code)
            ->assertJsonPath('message', $message);

        $this->assertCount(2, $response->json());
    }

    // ---------- unauthenticated ----------

    public function test_unauthenticated_cuando_falta_el_token(): void
    {
        $response = $this->getJson('/api/auth/me');

        $this->assertEnvelope($response, 401, 'unauthenticated', 'No autenticado. Token de acceso ausente o inválido.');
    }

    public function test_unauthenticated_cuando_el_token_es_invalido(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer token-invalido')
            ->getJson('/api/auth/me');

        $this->assertEnvelope($response, 401, 'unauthenticated', 'No autenticado. Token de acceso ausente o inválido.');
    }

    // ---------- invalid_credentials ----------

    public function test_invalid_credentials_en_login(): void
    {
        $user = User::factory()->create(['email' => 'inspector@example.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'contraseña-errónea',
        ]);

        $this->assertEnvelope($response, 401, 'invalid_credentials', 'Las credenciales proporcionadas son incorrectas.');
    }

    // ---------- user_inactive ----------

    public function test_user_inactive_en_login(): void
    {
        $user = User::factory()->create(['email' => 'inactivo@example.test']);
        $user->forceFill(['status' => false])->save();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertEnvelope($response, 403, 'user_inactive', 'El usuario está inactivo. Contacte al administrador.');
    }

    public function test_user_inactive_coincide_entre_login_y_token(): void
    {
        $user = User::factory()->create(['email' => 'inactivo@example.test']);
        $user->forceFill(['status' => false])->save();

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $token = $user->createToken('inspecta-api')->plainTextToken;

        $me = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me');

        $this->assertSame($login->json('code'), $me->json('code'));
        $this->assertSame($login->json('message'), $me->json('message'));
        $this->assertSame('user_inactive', $me->json('code'));
        $me->assertStatus(403);
    }

    public function test_usuario_inactivo_con_contraseña_incorrecta_recibe_invalid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'inactivo@example.test']);
        $user->forceFill(['status' => false])->save();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'contraseña-errónea',
        ]);

        $this->assertEnvelope($response, 401, 'invalid_credentials', 'Las credenciales proporcionadas son incorrectas.');
    }

    // ---------- validation_failed ----------

    public function test_validation_failed_conserva_los_errors(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'no-es-un-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'code', 'errors'])
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['email', 'password']);

        $this->assertCount(3, $response->json());
        $this->assertNotEmpty($response->json('message'));
        $this->assertIsString($response->json('message'));
    }

    // ---------- too_many_attempts ----------

    public function test_too_many_attempts_incluye_retry_after(): void
    {
        $user = User::factory()->create(['email' => 'inspector@example.test']);

        for ($intento = 1; $intento <= 5; $intento++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'contraseña-errónea',
            ])->assertStatus(401);
        }

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'contraseña-errónea',
        ]);

        $this->assertEnvelope($response, 429, 'too_many_attempts', 'Demasiados intentos. Inténtalo de nuevo más tarde.');

        $retryAfter = $response->headers->get('Retry-After');
        $this->assertNotNull($retryAfter);
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertSame('5', $response->headers->get('X-RateLimit-Limit'));
    }

    // ---------- not_found ----------

    public function test_not_found_para_ruta_inexistente(): void
    {
        $response = $this->getJson('/api/ruta-inexistente');

        $this->assertEnvelope($response, 404, 'not_found', 'Recurso no encontrado.');
    }

    public function test_not_found_no_filtra_nombres_de_modelo_ni_de_clase(): void
    {
        $organization = $this->organization('ORG-ERR');
        $user = $this->user($organization);

        $response = $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/error-probe/plants/'.(string) Str::uuid7());

        $this->assertEnvelope($response, 404, 'not_found', 'Recurso no encontrado.');

        $contenido = (string) $response->getContent();
        $this->assertStringNotContainsString('Plant', $contenido);
        $this->assertStringNotContainsString('App\\', $contenido);
        $this->assertStringNotContainsString('ModelNotFoundException', $contenido);
    }

    // ---------- cross_tenant_operation ----------

    public function test_cross_tenant_operation(): void
    {
        $organization = $this->organization('ORG-ERR');
        $user = $this->user($organization);

        $response = $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/error-probe/cross-tenant');

        $this->assertEnvelope(
            $response,
            422,
            'cross_tenant_operation',
            "El campo 'plant_id' referencia un recurso inexistente o de otra organización."
        );
    }

    // ---------- Códigos de contexto de tenant ----------

    public function test_organization_required(): void
    {
        $user = $this->user($this->organization('ORG-ERR'));

        $response = $this->actingAsApi($user)
            ->getJson('/api/error-probe/ping');

        $this->assertEnvelope($response, 400, 'organization_required', 'Cabecera X-Organization-Id requerida.');
    }

    public function test_organization_invalid(): void
    {
        $user = $this->user($this->organization('ORG-ERR'));

        $response = $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', 'no-es-un-uuid')
            ->getJson('/api/error-probe/ping');

        $this->assertEnvelope($response, 400, 'organization_invalid', 'El identificador de organización no es un UUID válido.');
    }

    public function test_organization_access_denied(): void
    {
        $user = $this->user($this->organization('ORG-ERR'));

        $response = $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', (string) Str::uuid7())
            ->getJson('/api/error-probe/ping');

        $this->assertEnvelope($response, 403, 'organization_access_denied', 'No tienes acceso a la organización indicada.');
    }

    public function test_organization_not_tenant(): void
    {
        $operator = $this->operatorOrganization();
        $user = $this->user($operator);

        $response = $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $operator->id)
            ->getJson('/api/error-probe/ping');

        $this->assertEnvelope($response, 403, 'organization_not_tenant', 'No tienes acceso a la organización indicada.');
    }

    public function test_mensaje_identico_entre_access_denied_y_not_tenant_con_codigos_distintos(): void
    {
        $operator = $this->operatorOrganization();
        $user = $this->user($this->organization('ORG-ERR'));

        $accessDenied = $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', (string) Str::uuid7())
            ->getJson('/api/error-probe/ping');

        $notTenant = $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $operator->id)
            ->getJson('/api/error-probe/ping');

        $accessDenied->assertStatus(403);
        $notTenant->assertStatus(403);

        $this->assertSame($accessDenied->json('message'), $notTenant->json('message'));
        $this->assertSame('No tienes acceso a la organización indicada.', $accessDenied->json('message'));
        $this->assertNotSame($accessDenied->json('code'), $notTenant->json('code'));
        $this->assertSame('organization_access_denied', $accessDenied->json('code'));
        $this->assertSame('organization_not_tenant', $notTenant->json('code'));
    }

    // ---------- Integridad de los catálogos ----------

    public function test_no_hay_codigos_repetidos_entre_tenant_error_code_y_api_error_code(): void
    {
        $tenantCodes = array_values(
            (new ReflectionClass(TenantErrorCode::class))->getConstants()
        );

        $apiCodes = array_map(
            fn (ApiErrorCode $case) => $case->value,
            ApiErrorCode::cases()
        );

        $todos = array_merge($tenantCodes, $apiCodes);

        $this->assertNotEmpty($tenantCodes);
        $this->assertNotEmpty($apiCodes);
        $this->assertCount(count(array_unique($todos)), $todos);
    }

    public function test_el_catalogo_central_registra_todos_los_codigos(): void
    {
        $tenantCodes = array_values(
            (new ReflectionClass(TenantErrorCode::class))->getConstants()
        );

        $apiCodes = array_map(
            fn (ApiErrorCode $case) => $case->value,
            ApiErrorCode::cases()
        );

        $esperados = array_merge($tenantCodes, $apiCodes);

        $catalogo = ApiErrorResponse::catalogCodes();
        sort($catalogo);
        sort($esperados);

        $this->assertSame($esperados, $catalogo);
    }

    // ---------- forbidden (D-39) ----------

    public function test_forbidden_por_abort_403_devuelve_code_forbidden_y_mensaje_fijo(): void
    {
        $user = $this->user($this->organization('ORG-FORB'));
        $org = Organization::where('code', 'ORG-FORB')->first();

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/error-probe/forbidden');

        $this->assertEnvelope($response, 403, 'forbidden', ApiErrorCode::FORBIDDEN_MESSAGE);
        $this->assertStringNotContainsString('mensaje personalizado que no debe aparecer', (string) $response->getContent());
    }

    public function test_forbidden_por_authorization_exception_no_filtra_mensaje(): void
    {
        $user = $this->user($this->organization('ORG-FORB2'));
        $org = Organization::where('code', 'ORG-FORB2')->first();

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/error-probe/authz');

        $this->assertEnvelope($response, 403, 'forbidden', ApiErrorCode::FORBIDDEN_MESSAGE);
        $this->assertStringNotContainsString('otro mensaje secreto', (string) $response->getContent());
    }

    public function test_forbidden_mensaje_fijo_hardcodeado(): void
    {
        // El mensaje fijo no debe cambiar aunque cambie la constante.
        $user = $this->user($this->organization('ORG-HC'));
        $org = Organization::where('code', 'ORG-HC')->first();

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/error-probe/authz');

        $this->assertEnvelope($response, 403, 'forbidden', 'No tienes permiso para realizar esta acción.');
    }

    public function test_forbidden_no_pisa_code_de_403_de_contexto(): void
    {
        $orgA = $this->organization('ORG-CTX-A');
        $orgB = $this->organization('ORG-CTX-B');
        $user = $this->user($orgA);

        // El usuario tiene membresía en orgA pero no en orgB: acceso denegado
        // por contexto, conserva su código (no se convierte en forbidden).
        $response = $this->actingAsApi($user)
            ->withHeader('X-Organization-Id', $orgB->id)
            ->getJson('/api/error-probe/ping');

        $response->assertStatus(403);
        $this->assertSame('organization_access_denied', $response->json('code'));
        $this->assertNotSame('forbidden', $response->json('code'));
    }

    public function test_un_404_no_cambia_con_forbidden(): void
    {
        $user = $this->user($this->organization('ORG-404'));
        $org = Organization::where('code', 'ORG-404')->first();

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/error-probe/plants/'.(string) Str::uuid7());

        $this->assertEnvelope($response, 404, 'not_found', 'Recurso no encontrado.');
    }
}
