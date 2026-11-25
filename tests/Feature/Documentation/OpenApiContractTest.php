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
                'administration',
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

        // Capacidades específicas de administración.
        $expectedAdministration = [
            'manage_users',
            'manage_memberships',
            'manage_roles',
            'view_locations',
            'manage_locations',
            'view_all_locations',
            'create_users',
            'assign_roles',
            'assign_locations',
        ];

        $administration = $properties['administration'];

        $this->assertSame('object', $administration['type']);

        $this->assertEqualsCanonicalizing(
            $expectedAdministration,
            $administration['required']
        );

        foreach ($expectedAdministration as $capability) {
            $this->assertArrayHasKey(
                $capability,
                $administration['properties']
            );

            $this->assertSame(
                'boolean',
                $administration['properties'][$capability]['type']
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
                fn (array $parameter): bool => ($parameter['name'] ?? null) === 'month'
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
            $operation['responses']['200']['content']['application/json']['schema']['$ref']
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
            $schemas['DashboardApprovalRate']['properties']['value']['nullable']
        );

        $this->assertTrue(
            $schemas['DashboardApprovalTrendPoint']['properties']['value']['nullable']
        );

        $this->assertSame(
            5,
            $schemas['DashboardCharts']['properties']['approval_trend']['maxItems']
        );

        $this->assertSame(
            4,
            $schemas['DashboardCharts']['properties']['incidents_by_risk']['maxItems']
        );

        $this->assertSame(
            ['low', 'medium', 'high', 'critical'],
            $schemas['DashboardRiskCount']['properties']['risk_level']['enum']
        );
    }

    public function test_dashboard_documenta_la_agrupacion_temporal(): void
    {
        $spec = Yaml::parse($this->spec());

        $operation = $spec['paths']['/dashboard']['get'];

        $granularity = collect($operation['parameters'])
            ->first(
                fn (array $parameter): bool => ($parameter['name'] ?? null) === 'granularity'
            );

        $this->assertNotNull($granularity);

        $this->assertSame('query', $granularity['in']);

        $this->assertFalse($granularity['required']);

        $this->assertSame(
            'string',
            $granularity['schema']['type']
        );

        $this->assertSame(
            ['weekly', 'monthly', 'yearly'],
            $granularity['schema']['enum']
        );

        $this->assertSame(
            'monthly',
            $granularity['schema']['default']
        );

        $this->assertArrayHasKey(
            422,
            $operation['responses']
        );
    }

    public function test_dashboard_documenta_los_intervalos_de_aprobacion(): void
    {
        $spec = Yaml::parse($this->spec());

        $schemas = $spec['components']['schemas'];

        $period = $schemas['DashboardPeriod'];

        $this->assertEqualsCanonicalizing([
            'month',
            'granularity',
            'date_from',
            'date_to',
            'previous_date_from',
            'previous_date_to',
        ], $period['required']);

        $this->assertSame(
            'string',
            $period['properties']['granularity']['type']
        );

        $this->assertSame(
            ['weekly', 'monthly', 'yearly'],
            $period['properties']['granularity']['enum']
        );

        $trend = $schemas['DashboardCharts']['properties']['approval_trend'];

        $this->assertSame('array', $trend['type']);

        $this->assertSame(4, $trend['minItems']);

        $this->assertSame(5, $trend['maxItems']);

        $this->assertSame(
            '#/components/schemas/DashboardApprovalTrendPoint',
            $trend['items']['$ref']
        );

        $point = $schemas['DashboardApprovalTrendPoint'];

        $this->assertEqualsCanonicalizing([
            'month',
            'period',
            'date_from',
            'date_to',
            'value',
            'approved',
            'rejected',
            'reviewed',
        ], $point['required']);

        $properties = $point['properties'];

        $this->assertSame(
            'string',
            $properties['month']['type']
        );

        $this->assertSame(
            'string',
            $properties['period']['type']
        );

        foreach (['date_from', 'date_to'] as $field) {
            $this->assertSame(
                'string',
                $properties[$field]['type']
            );

            $this->assertSame(
                'date',
                $properties[$field]['format']
            );
        }

        $this->assertSame(
            'number',
            $properties['value']['type']
        );

        $this->assertTrue(
            $properties['value']['nullable']
        );

        $this->assertSame(
            0,
            $properties['value']['minimum']
        );

        $this->assertSame(
            100,
            $properties['value']['maximum']
        );

        foreach (['approved', 'rejected', 'reviewed'] as $field) {
            $this->assertSame(
                'integer',
                $properties[$field]['type']
            );

            $this->assertSame(
                0,
                $properties[$field]['minimum']
            );
        }
    }

    public function test_inspection_online_documenta_los_campos_descriptivos(): void
    {
        $spec = Yaml::parse($this->spec());

        $schema = $spec['components']['schemas']['InspectionOnline'];

        $this->assertSame('object', $schema['type']);

        $fields = [
            'inspector_name',
            'plant_name',
            'area_name',
            'production_line_name',
            'checklist_name',
            'checklist_version',
        ];

        foreach ($fields as $field) {
            $this->assertArrayHasKey(
                $field,
                $schema['properties']
            );

            $this->assertContains(
                $field,
                $schema['required']
            );

            $this->assertSame(
                'string',
                $schema['properties'][$field]['type']
            );

            $this->assertTrue(
                $schema['properties'][$field]['nullable']
            );
        }
    }

    public function test_inspection_online_documenta_el_avance(): void
    {
        $spec = Yaml::parse($this->spec());

        $schema = $spec['components']['schemas']['InspectionOnline'];

        $properties = $schema['properties'];

        foreach ([
            'progress',
            'answered_questions',
            'required_questions',
        ] as $field) {
            $this->assertArrayHasKey($field, $properties);

            $this->assertContains(
                $field,
                $schema['required']
            );
        }

        $progress = $properties['progress'];

        $this->assertSame('number', $progress['type']);

        $this->assertTrue($progress['nullable']);

        $this->assertSame(0, $progress['minimum']);

        $this->assertSame(100, $progress['maximum']);

        foreach ([
            'answered_questions',
            'required_questions',
        ] as $field) {
            $this->assertSame(
                'integer',
                $properties[$field]['type']
            );

            $this->assertSame(
                0,
                $properties[$field]['minimum']
            );
        }
    }

    public function test_inspections_y_dashboard_comparten_el_contrato_de_avance(): void
    {
        $spec = Yaml::parse($this->spec());

        $schemas = $spec['components']['schemas'];

        $inspection = $schemas['InspectionOnline']['properties'];

        $dashboard = $schemas['DashboardRecentInspection']['properties'];

        foreach ([
            'plant_name',
            'area_name',
            'production_line_name',
            'checklist_name',
            'checklist_version',
            'progress',
            'answered_questions',
            'required_questions',
        ] as $field) {
            $this->assertArrayHasKey($field, $inspection);

            $this->assertArrayHasKey($field, $dashboard);

            foreach ([
                'type',
                'nullable',
                'minimum',
                'maximum',
            ] as $attribute) {
                $this->assertSame(
                    $inspection[$field][$attribute] ?? null,
                    $dashboard[$field][$attribute] ?? null,
                    "El atributo {$attribute} de {$field} no coincide."
                );
            }
        }
    }

    public function test_reports_documenta_los_parametros_y_respuestas(): void
    {
        $spec = Yaml::parse($this->spec());

        $path = $spec['paths']['/reports/summary'];

        $operation = $path['get'];

        $this->assertContains([
            '$ref' => '#/components/parameters/organizationIdHeader',
        ], $path['parameters']);

        $this->assertContains(
            ['bearerAuth' => []],
            $operation['security']
        );

        $parameters = collect(
            $operation['parameters']
        )->keyBy('name');

        $this->assertEqualsCanonicalizing([
            'date_from',
            'date_to',
            'plant_id',
            'area_id',
            'production_line_id',
        ], $parameters->keys()->all());

        foreach (['date_from', 'date_to'] as $field) {
            $parameter = $parameters->get($field);

            $this->assertSame(
                'query',
                $parameter['in']
            );

            $this->assertTrue($parameter['required']);

            $this->assertSame(
                'string',
                $parameter['schema']['type']
            );

            $this->assertSame(
                'date',
                $parameter['schema']['format']
            );
        }

        foreach ([
            'plant_id',
            'area_id',
            'production_line_id',
        ] as $field) {
            $parameter = $parameters->get($field);

            $this->assertSame(
                'query',
                $parameter['in']
            );

            $this->assertFalse($parameter['required']);

            $this->assertSame(
                'string',
                $parameter['schema']['type']
            );

            $this->assertSame(
                'uuid',
                $parameter['schema']['format']
            );
        }

        foreach ([200, 400, 401, 403, 422] as $status) {
            $this->assertArrayHasKey(
                $status,
                $operation['responses']
            );
        }

        $this->assertSame(
            '#/components/schemas/ReportSummaryResponse',
            $operation['responses'][200]['content']['application/json']['schema']['$ref']
        );
    }

    public function test_reports_documenta_las_secciones_y_capacidades(): void
    {
        $spec = Yaml::parse($this->spec());

        $schemas = $spec['components']['schemas'];

        $summary = $schemas['ReportSummaryResponse'];

        $this->assertSame(
            'object',
            $summary['type']
        );

        $this->assertEqualsCanonicalizing([
            'organization_id',
            'period',
            'filters',
            'scope',
            'capabilities',
            'inspections',
            'incidents',
            'corrective_actions',
        ], $summary['required']);

        $this->assertSame(
            ['all', 'own'],
            $summary['properties']['scope']['enum']
        );

        $capabilities = $schemas['ReportCapabilities'];

        $this->assertEqualsCanonicalizing([
            'inspections',
            'incidents',
            'corrective_actions',
        ], $capabilities['required']);

        foreach ($capabilities['properties'] as $property) {
            $this->assertSame(
                'boolean',
                $property['type']
            );
        }

        $sections = [
            'inspections' => 'ReportInspectionStatistics',
            'incidents' => 'ReportIncidentStatistics',
            'corrective_actions' => 'ReportCorrectiveActionStatistics',
        ];

        foreach ($sections as $field => $schema) {
            $this->assertSame(
                "#/components/schemas/{$schema}",
                $summary['properties'][$field]['$ref']
            );

            $this->assertTrue(
                $schemas[$schema]['nullable']
            );

            $this->assertSame(
                'object',
                $schemas[$schema]['type']
            );
        }
    }

    public function test_reports_documenta_las_tasas_y_distribuciones(): void
    {
        $spec = Yaml::parse($this->spec());

        $schemas = $spec['components']['schemas'];

        $rates = [
            'ReportInspectionStatistics' => [
                'completion_rate',
                'approval_rate',
            ],
            'ReportCorrectiveActionStatistics' => [
                'verification_rate',
            ],
            'ReportIncidentArea' => [
                'percentage',
            ],
        ];

        foreach ($rates as $schema => $fields) {
            foreach ($fields as $field) {
                $property =
                    $schemas[$schema]['properties'][$field];

                $this->assertSame(
                    'number',
                    $property['type']
                );

                $this->assertTrue(
                    $property['nullable']
                );

                $this->assertSame(
                    0,
                    $property['minimum']
                );

                $this->assertSame(
                    100,
                    $property['maximum']
                );
            }
        }

        $inspections =
            $schemas['ReportInspectionStatistics'];

        $incidents =
            $schemas['ReportIncidentStatistics'];

        $actions =
            $schemas['ReportCorrectiveActionStatistics'];

        $this->assertEqualsCanonicalizing([
            'draft',
            'completed',
            'confirmed',
            'rejected',
        ], $inspections['properties']['by_status']['required']);

        $this->assertEqualsCanonicalizing([
            'open',
            'in_progress',
            'resolved',
            'closed',
        ], $incidents['properties']['by_status']['required']);

        $this->assertEqualsCanonicalizing([
            'low',
            'medium',
            'high',
            'critical',
        ], $incidents['properties']['by_risk_level']['required']);

        $this->assertEqualsCanonicalizing([
            'pending',
            'in_progress',
            'completed',
            'verified',
        ], $actions['properties']['by_status']['required']);

        $this->assertSame(
            'array',
            $incidents['properties']['by_area']['type']
        );

        $this->assertSame(
            '#/components/schemas/ReportIncidentArea',
            $incidents['properties']['by_area']['items']['$ref']
        );
    }
}
