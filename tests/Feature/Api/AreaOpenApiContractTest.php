<?php

namespace Tests\Feature\Api;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AreaOpenApiContractTest extends TestCase
{
    public function test_contrato_cat02_refleja_el_post_implementado_y_deja_planeadas_las_demas_operaciones(): void
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));
        $path = $spec['paths']['/plants/{plantId}/areas'];

        $this->assertArrayNotHasKey('x-status', $path);
        $this->assertArrayNotHasKey('x-status', $path['get']);
        $this->assertArrayNotHasKey('x-status', $path['post']);
        $this->assertSame(['bearerAuth' => []], $path['post']['security'][0]);
        $this->assertContains('#/components/parameters/organizationIdHeader', array_column($path['parameters'], '$ref'));
        $this->assertSame('#/components/parameters/plantIdParam', $path['post']['parameters'][0]['$ref']);
        $this->assertTrue($path['post']['requestBody']['required']);
        $this->assertSame(['application/json'], array_keys($path['post']['requestBody']['content']));
        $this->assertSame('#/components/schemas/AreaCreateInput', $path['post']['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame('#/components/schemas/AreaCreatedResponse', $path['post']['responses']['201']['content']['application/json']['schema']['$ref']);
        $this->assertEqualsCanonicalizing(['201', '400', '401', '403', '404', '415', '422'], array_map('strval', array_keys($path['post']['responses'])));

        $input = $spec['components']['schemas']['AreaCreateInput'];
        $this->assertFalse($input['additionalProperties']);
        $this->assertEqualsCanonicalizing(['name', 'code'], $input['required']);
        $this->assertEqualsCanonicalizing(['name', 'code', 'type', 'description'], array_keys($input['properties']));
        $this->assertArrayNotHasKey('pattern', $input['properties']['code']);
        $this->assertArrayNotHasKey('maxLength', $input['properties']['code']);
        $this->assertTrue($input['properties']['type']['nullable']);
        $this->assertTrue($input['properties']['description']['nullable']);

        $created = $spec['components']['schemas']['AreaCreated'];
        $this->assertFalse($created['additionalProperties']);
        $this->assertEqualsCanonicalizing(['id', 'organization_id', 'plant_id', 'name', 'code', 'type', 'description', 'status'], $created['required']);
        $this->assertEqualsCanonicalizing($created['required'], array_keys($created['properties']));
        $this->assertSame([true], $created['properties']['status']['enum']);
        $this->assertArrayNotHasKey('created_at', $created['properties']);
        $this->assertArrayNotHasKey('deleted_at', $created['properties']);
        $this->assertArrayNotHasKey('production_lines', $created['properties']);

        $this->assertStringContainsString('errors.plantId', $path['post']['responses']['422']['description']);
        $this->assertStringContainsString('errors.code', $path['post']['responses']['422']['description']);
        $this->assertArrayHasKey('inactivePlant', $path['post']['responses']['422']['content']['application/json']['examples']);
        $this->assertArrayHasKey('duplicateCode', $path['post']['responses']['422']['content']['application/json']['examples']);
        $this->assertSame('2.3.0', $spec['info']['version']);
    }
}
