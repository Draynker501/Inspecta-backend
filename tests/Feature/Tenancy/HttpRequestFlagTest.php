<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\MissingTenantContextException;
use App\Models\Plant;
use App\Support\Tenancy\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HttpRequestFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/http-flag', function () {
            return response()->json([
                'flag' => app(TenantContext::class)->isHttpRequest(),
            ]);
        });

        Route::middleware('web')->get('/_test/http-no-context', function () {
            return response()->json([
                'count' => Plant::query()->count(),
            ]);
        });
    }

    public function test_flag_activo_durante_peticion_http(): void
    {
        $response = $this->get('/_test/http-flag');

        $response->assertOk();
        $response->assertJson(['flag' => true]);

        $this->assertFalse(app(TenantContext::class)->isHttpRequest());
    }

    public function test_flag_no_persiste_entre_peticiones(): void
    {
        $this->get('/_test/http-flag')->assertOk();

        $this->assertFalse(app(TenantContext::class)->isHttpRequest());

        $this->get('/_test/http-flag')->assertOk();
        $this->assertFalse(app(TenantContext::class)->isHttpRequest());
    }

    public function test_peticion_http_sin_contexto_lanza_missing_tenant_context(): void
    {
        config(['tenancy.strict_mode' => true]);

        $this->withoutExceptionHandling();

        $this->expectException(MissingTenantContextException::class);

        $this->get('/_test/http-no-context');
    }

    public function test_comando_artisan_sin_contexto_sigue_exento(): void
    {
        config(['tenancy.strict_mode' => true]);

        $scope = new OrganizationScope;
        $builder = Plant::query();
        $scope->apply($builder, new Plant);

        $this->assertTrue(true);
    }

    public function test_worker_de_cola_sin_contexto_lanza_excepcion(): void
    {
        config(['tenancy.strict_mode' => true]);
        config(['tenancy.allow_console_without_tenant' => true]);

        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'queue:work'];

        try {
            $this->expectException(MissingTenantContextException::class);

            $scope = new OrganizationScope;
            $builder = Plant::query();
            $scope->apply($builder, new Plant);
        } finally {
            if ($previousArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previousArgv;
            }
        }
    }
}
