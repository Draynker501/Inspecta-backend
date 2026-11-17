<?php

namespace Tests\Feature\Documentation;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class InspectionCompletionOpenApiContractTest extends TestCase
{
    public function test_contract_and_route_match(): void
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));
        $operation = $spec['paths']['/inspections/{inspectionId}/complete']['post'];
        $this->assertSame('2.3.0', $spec['info']['version']);
        $this->assertSame('implemented', $operation['x-status']);
        $this->assertSame('planned', $spec['paths']['/inspections/{inspectionId}/status']['x-status']);
        $this->assertTrue($operation['requestBody']['required']);
        $schema = $operation['requestBody']['content']['application/json']['schema'];
        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(0, $schema['maxProperties']);
        $this->assertSame('#/components/schemas/InspectionOnline', $operation['responses'][200]['content']['application/json']['schema']['properties']['data']['$ref']);
        foreach ([400, 401, 403, 404, 422] as $status) {
            $this->assertArrayHasKey($status, $operation['responses']);
        }
        $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'api/inspections/{inspectionId}/complete' && in_array('POST', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('api.tenant', $route->middleware());
    }
}
