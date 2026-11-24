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

    public function test_la_version_del_contrato_es_2_4_0(): void
    {
        $this->assertStringContainsString(
            'version: 2.4.0',
            $this->spec()
        );
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

    public function test_documenta_dashboard_y_su_contrato(): void
    {
        $spec = Yaml::parse($this->spec());

        $this->assertArrayHasKey(
            '/dashboard',
            $spec['paths']
        );

        $endpoint = $spec['paths']['/dashboard'];

        $this->assertArrayHasKey('get', $endpoint);

        $this->assertContains(
            ['$ref' => '#/components/parameters/organizationIdHeader'],
            $endpoint['parameters']
        );

        $operation = $endpoint['get'];

        $this->assertContains(
            ['bearerAuth' => []],
            $operation['security']
        );

        $month = collect($operation['parameters'])
            ->first(
                fn(array $parameter): bool =>
                    ($parameter['name'] ?? null) === 'month'
            );

        $this->assertNotNull($month);

        $this->assertSame('query', $month['in']);

        $this->assertFalse($month['required']);

        $this->assertSame(
            'string',
            $month['schema']['type']
        );

        foreach ([200, 400, 401, 403, 422] as $status) {
            $this->assertArrayHasKey(
                $status,
                $operation['responses']
            );
        }

        $this->assertSame(
            '#/components/schemas/DashboardResponse',
            $operation['responses']['200']
            ['content']['application/json']
            ['schema']['$ref']
        );
    }

    public function test_dashboard_response_define_los_esquemas_requeridos(): void
    {
        $spec = Yaml::parse($this->spec());

        $schemas = $spec['components']['schemas'];

        $expectedSchemas = [
            'DashboardResponse',
            'DashboardPeriod',
            'DashboardCapabilities',
            'DashboardSummary',
            'DashboardApprovalRate',
            'DashboardInspectionCount',
            'DashboardOpenIncidents',
            'DashboardPendingActionCount',
            'DashboardCharts',
            'DashboardApprovalTrendPoint',
            'DashboardRiskCount',
            'DashboardRecentInspection',
            'DashboardPendingAction',
        ];

        foreach ($expectedSchemas as $schema) {
            $this->assertArrayHasKey(
                $schema,
                $schemas
            );
        }

        $response = $schemas['DashboardResponse'];

        $this->assertSame('object', $response['type']);

        $this->assertEqualsCanonicalizing([
            'organization_id',
            'period',
            'capabilities',
            'summary',
            'charts',
            'recent_inspections',
            'pending_corrective_actions',
        ], $response['required']);

        $this->assertSame(
            3,
            $response['properties']['recent_inspections']['maxItems']
        );

        $this->assertSame(
            3,
            $response['properties']['pending_corrective_actions']['maxItems']
        );

        $capabilities = $schemas['DashboardCapabilities'];

        $this->assertEqualsCanonicalizing([
            'inspections',
            'incidents',
            'corrective_actions',
            'create_inspection',
        ], $capabilities['required']);

        foreach ($capabilities['properties'] as $property) {
            $this->assertSame(
                'boolean',
                $property['type']
            );
        }

        $this->assertArrayNotHasKey(
            'required',
            $schemas['DashboardSummary']
        );

        $this->assertArrayNotHasKey(
            'required',
            $schemas['DashboardCharts']
        );

        $this->assertTrue(
            $schemas['DashboardApprovalRate']
            ['properties']['value']['nullable']
        );

        $this->assertTrue(
            $schemas['DashboardApprovalTrendPoint']
            ['properties']['value']['nullable']
        );

        $this->assertSame(
            5,
            $schemas['DashboardCharts']
            ['properties']['approval_trend']['maxItems']
        );

        $this->assertSame(
            4,
            $schemas['DashboardCharts']
            ['properties']['incidents_by_risk']['maxItems']
        );

        $this->assertSame(
            ['low', 'medium', 'high', 'critical'],
            $schemas['DashboardRiskCount']
            ['properties']['risk_level']['enum']
        );
    }
}