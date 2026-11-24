<?php

namespace Tests\Feature\Documentation;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    private function spec(): string
    {
        return (string) file_get_contents(base_path('openapi.yaml'));
    }

    public function test_la_version_del_contrato_es_2_3_0(): void
    {
        $this->assertStringContainsString('version: 2.3.0', $this->spec());
    }

    public function test_documenta_la_cabecera_x_organization_id(): void
    {
        $spec = $this->spec();

        $this->assertStringContainsString('X-Organization-Id', $spec);
        $this->assertStringContainsString('organizationIdHeader', $spec);
    }

    public function test_documenta_auth_organizations(): void
    {
        $this->assertStringContainsString('/auth/organizations:', $this->spec());
    }

    public function test_documenta_auth_capabilities_y_su_esquema(): void
    {
        $spec = Yaml::parse($this->spec());

        $this->assertArrayHasKey(
            '/auth/capabilities',
            $spec['paths']
        );

        $endpoint = $spec['paths']['/auth/capabilities'];

        $this->assertArrayHasKey('get', $endpoint);

        $this->assertContains(
            ['$ref' => '#/components/parameters/organizationIdHeader'],
            $endpoint['parameters']
        );

        $this->assertSame(
            '#/components/schemas/CapabilitiesResponse',
            $endpoint['get']['responses']['200']['content']['application/json']['schema']['$ref']
        );

        $this->assertArrayHasKey(
            'CapabilitiesResponse',
            $spec['components']['schemas']
        );

        $schema = $spec['components']['schemas']['CapabilitiesResponse'];

        $this->assertSame('object', $schema['type']);

        $this->assertEqualsCanonicalizing(
            [
                'organization_id',
                'roles',
                'modules',
                'corrective_actions',
            ],
            $schema['required']
        );

        $properties = $schema['properties'];

        // Organización activa.
        $this->assertSame(
            'string',
            $properties['organization_id']['type']
        );

        $this->assertSame(
            'uuid',
            $properties['organization_id']['format']
        );

        // Roles activos.
        $this->assertSame(
            'array',
            $properties['roles']['type']
        );

        $this->assertSame(
            'string',
            $properties['roles']['items']['type']
        );

        // Capacidades generales por módulo.
        $expectedModules = [
            'dashboard',
            'inspections',
            'incidents',
            'corrective_actions',
            'checklists',
            'norms',
            'reports',
            'administration',
        ];

        $modules = $properties['modules'];

        $this->assertSame('object', $modules['type']);

        $this->assertEqualsCanonicalizing(
            $expectedModules,
            $modules['required']
        );

        foreach ($expectedModules as $module) {
            $this->assertArrayHasKey(
                $module,
                $modules['properties']
            );

            $this->assertSame(
                'boolean',
                $modules['properties'][$module]['type']
            );
        }

        // Capacidades específicas de acciones correctivas.
        $expectedCorrectiveActions = [
            'view',
            'create',
            'update',
            'record_update',
            'verify',
        ];

        $correctiveActions = $properties['corrective_actions'];

        $this->assertSame('object', $correctiveActions['type']);

        $this->assertEqualsCanonicalizing(
            $expectedCorrectiveActions,
            $correctiveActions['required']
        );

        foreach ($expectedCorrectiveActions as $capability) {
            $this->assertArrayHasKey(
                $capability,
                $correctiveActions['properties']
            );

            $this->assertSame(
                'boolean',
                $correctiveActions['properties'][$capability]['type']
            );
        }
    }

    public function test_el_schema_organization_incluye_type(): void
    {
        $spec = $this->spec();

        $this->assertStringContainsString(
            'enum: [client, operator]',
            $spec
        );
    }

    public function test_la_descripcion_menciona_v1_4_0(): void
    {
        $this->assertStringContainsString(
            'v1.4.0 (aditiva, M1.5)',
            $this->spec()
        );
    }
}