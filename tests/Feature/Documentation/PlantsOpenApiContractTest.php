<?php

namespace Tests\Feature\Documentation;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class PlantsOpenApiContractTest extends TestCase
{
    public function test_post_de_plantas_documenta_el_contrato_de_creacion(): void
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));
        $path = $spec['paths']['/plants'];
        $post = $path['post'];
        $schemas = $spec['components']['schemas'];
        $input = $schemas['PlantCreateInput'];
        $created = $schemas['PlantCreated'];

        $this->assertArrayNotHasKey('x-status', $post);
        $this->assertSame([['bearerAuth' => []]], $spec['security']);
        $this->assertContains(['$ref' => '#/components/parameters/organizationIdHeader'], $path['parameters']);
        $this->assertSame('#/components/schemas/PlantCreateInput', $post['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertFalse($input['additionalProperties']);
        $this->assertEqualsCanonicalizing(['name', 'code'], $input['required']);
        $this->assertEqualsCanonicalizing(['name', 'code', 'address', 'description'], array_keys($input['properties']));
        $this->assertSame(150, $input['properties']['name']['maxLength']);
        $this->assertTrue($input['properties']['address']['nullable']);
        $this->assertTrue($input['properties']['description']['nullable']);
        $this->assertStringContainsString('50 caracteres', $input['properties']['code']['description']);
        $this->assertStringContainsString('valor bruto', $input['properties']['code']['description']);
        $this->assertSame(1, $input['properties']['code']['minLength']);
        $this->assertSame(['type', 'minLength', 'description', 'example'], array_keys($input['properties']['code']));
        $this->assertStringContainsString('plants.manage', $post['description']);
        $this->assertStringContainsString('query', $post['description']);
        $this->assertStringContainsString('archivo adjunto', $post['description']);

        $response = $post['responses'][201]['content']['application/json']['schema'];
        $this->assertSame(['data'], $response['required']);
        $this->assertFalse($response['additionalProperties']);
        $this->assertSame('#/components/schemas/PlantCreated', $response['properties']['data']['$ref']);
        $fields = ['id', 'organization_id', 'name', 'code', 'address', 'description', 'status'];
        $this->assertEqualsCanonicalizing($fields, $created['required']);
        $this->assertEqualsCanonicalizing($fields, array_keys($created['properties']));
        $this->assertFalse($created['additionalProperties']);
        $this->assertArrayNotHasKey('Location', $post['responses'][201]['headers'] ?? []);
        foreach ([400, 401, 403, 422] as $status) {
            $this->assertArrayHasKey($status, $post['responses']);
        }
        $this->assertStringContainsString('validation_failed', $post['responses'][422]['description']);
        $this->assertStringContainsString('Otros errores SQL no se convierten en validación', $post['description']);
    }

    public function test_get_documenta_paginacion_y_jerarquia_heredada_sin_efecto(): void
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));
        $get = $spec['paths']['/plants']['get'];
        $this->assertStringContainsString('plants.view', $get['description']);
        $this->assertStringContainsString('locations.view_all', $get['description']);
        $this->assertContains(['$ref' => '#/components/parameters/pageParam'], $get['parameters']);
        $this->assertContains(['$ref' => '#/components/parameters/perPageParam'], $get['parameters']);
        $hierarchy = collect($get['parameters'])->first(fn ($parameter) => ($parameter['name'] ?? null) === 'include_hierarchy');
        $this->assertTrue($hierarchy['deprecated']);
        $this->assertStringContainsString('ignorado', $hierarchy['description']);
        $this->assertStringContainsString('no devuelve áreas', $hierarchy['description']);
        $response = $get['responses'][200]['content']['application/json']['schema'];
        $this->assertArrayHasKey('data', $response['properties']);
        $this->assertArrayHasKey('meta', $response['properties']);
        $error = $get['responses'][422];
        $this->assertStringContainsString('validation_failed', $error['description']);
        $this->assertSame('#/components/schemas/Error', $error['content']['application/json']['schema']['$ref']);
        $this->assertSame('planned', $spec['paths']['/plants/{plantId}']['x-status']);
        $this->assertSame('#/components/schemas/PlantInput', $spec['paths']['/plants/{plantId}']['put']['requestBody']['content']['application/json']['schema']['$ref']);
    }
}
