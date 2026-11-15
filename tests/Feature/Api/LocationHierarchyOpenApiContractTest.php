<?php

namespace Tests\Feature\Api;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class LocationHierarchyOpenApiContractTest extends TestCase
{
    public function test_contrato_jerarquico_refleja_el_endpoint_implementado(): void
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));
        $path = $spec['paths']['/locations/hierarchy'];

        $this->assertArrayNotHasKey('x-status', $path);
        $this->assertArrayNotHasKey('x-status', $path['get']);
        $this->assertContains(['$ref' => '#/components/parameters/organizationIdHeader'], $path['parameters']);
        $this->assertEqualsCanonicalizing(
            ['page', 'per_page', 'plant_id', 'area_id'],
            array_map(fn ($p) => $p['name'], $path['get']['parameters'])
        );

        $schemas = $spec['components']['schemas'];
        $this->assertFalse($schemas['LocationHierarchyPlant']['additionalProperties']);
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'code', 'status', 'areas'],
            $schemas['LocationHierarchyPlant']['required']
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'code', 'type', 'status', 'production_lines'],
            $schemas['LocationHierarchyArea']['required']
        );
        $this->assertFalse($schemas['LocationHierarchyArea']['additionalProperties']);
        $this->assertTrue($schemas['LocationHierarchyArea']['properties']['type']['nullable']);
        $this->assertFalse($schemas['LocationHierarchyLine']['additionalProperties']);
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'code', 'status'],
            $schemas['LocationHierarchyLine']['required']
        );

        $this->assertSame('2.3.0', $spec['info']['version']);
        foreach ([200, 400, 401, 403, 422] as $status) {
            $this->assertArrayHasKey($status, $path['get']['responses']);
        }
    }
}
