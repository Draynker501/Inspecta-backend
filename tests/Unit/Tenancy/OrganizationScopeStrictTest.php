<?php

namespace Tests\Unit\Tenancy;

use App\Exceptions\MissingTenantContextException;
use App\Models\Plant;
use App\Support\Tenancy\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HttpOrganizationScope extends OrganizationScope
{
    protected function runningInConsole(): bool
    {
        return false;
    }
}

class OrganizationScopeStrictTest extends TestCase
{
    use RefreshDatabase;

    public function test_modo_permisivo_no_lanza_sin_contexto(): void
    {
        config(['tenancy.strict_mode' => false]);

        $scope = new OrganizationScope;
        $builder = Plant::query();
        $scope->apply($builder, new Plant);

        // Sin filtro aplicado: la consulta no tiene where organization_id.
        $this->assertStringNotContainsString('organization_id', $builder->toSql());
    }

    public function test_modo_estricto_en_http_lanza_sin_contexto(): void
    {
        config(['tenancy.strict_mode' => true]);

        $this->expectException(MissingTenantContextException::class);

        $scope = new HttpOrganizationScope;
        $builder = Plant::query();
        $scope->apply($builder, new Plant);
    }

    public function test_modo_estricto_permite_without_tenant(): void
    {
        config(['tenancy.strict_mode' => true]);

        $context = app(TenantContext::class);
        $context->withoutTenant('test', function () {
            $scope = new HttpOrganizationScope;
            $builder = Plant::query();
            $scope->apply($builder, new Plant);

            $this->assertStringNotContainsString('organization_id', $builder->toSql());
        });
    }

    public function test_modo_estricto_en_consola_no_lanza(): void
    {
        config(['tenancy.strict_mode' => true]);

        $scope = new OrganizationScope;
        $builder = Plant::query();
        $scope->apply($builder, new Plant);

        // No lanza; sin filtro (consola exenta).
        $this->assertTrue(true);
    }
}
