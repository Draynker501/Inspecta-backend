<?php

namespace Tests\Feature\Documentation;

use App\Http\Controllers\InspectionController;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class InspectionReviewOpenApiContractTest extends TestCase
{
    public function test_review_contract_and_route_match(): void
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));
        $path = $spec['paths']['/inspections/{inspectionId}/status'];
        $operation = $path['put'];
        $this->assertSame('2.4.0', $spec['info']['version']);
        $this->assertSame('implemented', $path['x-status']);
        $this->assertTrue($operation['requestBody']['required']);
        $this->assertSame('#/components/schemas/InspectionStatusInput', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $schema = $spec['components']['schemas']['InspectionStatusInput'];
        $this->assertCount(2, $schema['oneOf']);
        foreach ($schema['oneOf'] as $variant) {
            $this->assertFalse($variant['additionalProperties']);
        }
        [$confirm, $reject] = $schema['oneOf'];
        $this->assertSame(['confirm'], $confirm['properties']['action']['enum']);
        $this->assertSame(['action'], $confirm['required']);
        $this->assertArrayNotHasKey('supervisor_observations', $confirm['properties']);
        $this->assertSame(['reject'], $reject['properties']['action']['enum']);
        $this->assertSame(['action', 'supervisor_observations'], $reject['required']);
        $this->assertSame('string', $reject['properties']['supervisor_observations']['type']);
        $this->assertSame(1, $reject['properties']['supervisor_observations']['minLength']);
        $this->assertArrayNotHasKey('nullable', $reject['properties']['supervisor_observations']);
        $this->assertSame(65535, $reject['properties']['supervisor_observations']['maxLength']);
        foreach (['InspectionOnline', 'InspectionBase'] as $dto) {
            $this->assertStringContainsString('autor de la evaluación', $spec['components']['schemas'][$dto]['properties']['supervisor_id']['description'], $dto);
            $this->assertArrayNotHasKey('answers', $spec['components']['schemas'][$dto]['properties']);
        }
        $this->assertSame('#/components/schemas/InspectionOnline', $operation['responses'][200]['content']['application/json']['schema']['properties']['data']['$ref']);
        foreach ([400, 401, 403, 404, 422] as $status) {
            $this->assertArrayHasKey($status, $operation['responses']);
        }
        $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'api/inspections/{inspectionId}/status' && in_array('PUT', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('api.tenant', $route->middleware());
        $this->assertSame(InspectionController::class.'@review', $route->getActionName());
    }
}
