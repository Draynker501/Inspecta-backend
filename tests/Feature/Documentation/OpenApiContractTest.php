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

    public function test_documenta_los_endpoints_de_administracion_de_usuarios(): void
    {
        $spec = Yaml::parse($this->spec());

        $operations = [
            ['/users', 'get', 200, 'AdministrationUsersResponse'],
            ['/users', 'post', 201, 'AdministrationUserResponse'],
            ['/users/{userId}', 'get', 200, 'AdministrationUserResponse'],
            [
                '/users/{userId}/membership/status',
                'patch',
                200,
                'AdministrationUserResponse',
            ],
        ];

        foreach ($operations as [$path, $method, $success, $schema]) {
            $this->assertArrayHasKey($path, $spec['paths']);

            $endpoint = $spec['paths'][$path];

            $this->assertSame('implemented', $endpoint['x-status']);

            $this->assertContains(
                ['$ref' => '#/components/parameters/organizationIdHeader'],
                $endpoint['parameters']
            );

            $this->assertArrayHasKey($method, $endpoint);

            $operation = $endpoint[$method];

            $this->assertContains('Users', $operation['tags']);

            foreach ([$success, 400, 401, 403, 422] as $status) {
                $this->assertArrayHasKey(
                    $status,
                    $operation['responses']
                );
            }

            $this->assertSame(
                '#/components/schemas/'.$schema,
                $operation['responses'][$success]['content']['application/json']['schema']['$ref']
            );

            if (str_contains($path, '{userId}')) {
                $this->assertContains(
                    ['$ref' => '#/components/parameters/userIdParam'],
                    $operation['parameters']
                );

                $this->assertArrayHasKey(
                    404,
                    $operation['responses']
                );
            }

            if (in_array($method, ['post', 'patch'], true)) {
                $this->assertArrayHasKey(
                    415,
                    $operation['responses']
                );

                $this->assertTrue(
                    $operation['requestBody']['required']
                );
            }
        }

        $this->assertArrayNotHasKey(
            'put',
            $spec['paths']['/users/{userId}']
        );

        $this->assertArrayNotHasKey(
            'patch',
            $spec['paths']['/users/{userId}']
        );
    }

    public function test_administracion_de_usuarios_documenta_los_filtros(): void
    {
        $spec = Yaml::parse($this->spec());

        $parameters = collect(
            $spec['paths']['/users']['get']['parameters']
        )->keyBy('name');

        $this->assertEqualsCanonicalizing(
            ['page', 'per_page', 'q', 'status', 'role_id'],
            $parameters->keys()->all()
        );

        foreach ($parameters as $parameter) {
            $this->assertSame('query', $parameter['in']);

            $this->assertFalse(
                $parameter['required'] ?? false
            );
        }

        $page = $parameters['page']['schema'];

        $this->assertSame('integer', $page['type']);
        $this->assertSame(1, $page['minimum']);
        $this->assertSame(1, $page['default']);

        $perPage = $parameters['per_page']['schema'];

        $this->assertSame('integer', $perPage['type']);
        $this->assertSame(1, $perPage['minimum']);
        $this->assertSame(100, $perPage['maximum']);
        $this->assertSame(15, $perPage['default']);

        $search = $parameters['q']['schema'];

        $this->assertSame('string', $search['type']);
        $this->assertSame(100, $search['maxLength']);

        $status = $parameters['status']['schema'];

        $this->assertSame('string', $status['type']);

        $this->assertEqualsCanonicalizing(
            ['active', 'revoked'],
            $status['enum']
        );

        $roleId = $parameters['role_id']['schema'];

        $this->assertSame('string', $roleId['type']);
        $this->assertSame('uuid', $roleId['format']);
    }

    public function test_administracion_de_usuarios_documenta_los_esquemas_de_entrada(): void
    {
        $spec = Yaml::parse($this->spec());

        $schemas = $spec['components']['schemas'];

        $createOperation = $spec['paths']['/users']['post'];

        $this->assertSame(
            '#/components/schemas/AdministrationUserCreateInput',
            $createOperation['requestBody']['content']['application/json']['schema']['$ref']
        );

        $create = $schemas['AdministrationUserCreateInput'];

        $this->assertSame('object', $create['type']);
        $this->assertFalse($create['additionalProperties']);

        $expectedFields = [
            'name',
            'email',
            'password',
            'password_confirmation',
        ];

        $this->assertEqualsCanonicalizing(
            $expectedFields,
            $create['required']
        );

        $this->assertEqualsCanonicalizing(
            $expectedFields,
            array_keys($create['properties'])
        );

        $properties = $create['properties'];

        $this->assertSame(
            150,
            $properties['name']['maxLength']
        );

        $this->assertSame(
            'email',
            $properties['email']['format']
        );

        $this->assertSame(
            255,
            $properties['email']['maxLength']
        );

        $this->assertSame(
            12,
            $properties['password']['minLength']
        );

        $this->assertSame(
            72,
            $properties['password']['maxLength']
        );

        foreach (['password', 'password_confirmation'] as $field) {
            $this->assertSame(
                'password',
                $properties[$field]['format']
            );

            $this->assertTrue(
                $properties[$field]['writeOnly']
            );
        }

        $membershipOperation =
            $spec['paths']['/users/{userId}/membership/status']['patch'];

        $this->assertSame(
            '#/components/schemas/AdministrationMembershipStatusInput',
            $membershipOperation['requestBody']['content']['application/json']['schema']['$ref']
        );

        $membership = $schemas['AdministrationMembershipStatusInput'];

        $this->assertSame('object', $membership['type']);
        $this->assertFalse($membership['additionalProperties']);

        $this->assertSame(
            ['status'],
            $membership['required']
        );

        $this->assertSame(
            ['status'],
            array_keys($membership['properties'])
        );

        $this->assertEqualsCanonicalizing(
            ['active', 'revoked'],
            $membership['properties']['status']['enum']
        );
    }

    public function test_administracion_de_usuarios_documenta_los_esquemas_de_respuesta(): void
    {
        $spec = Yaml::parse($this->spec());

        $schemas = $spec['components']['schemas'];

        $expectedSchemas = [
            'AdministrationUser' => [
                'id',
                'name',
                'email',
                'account_status',
                'membership',
                'roles',
                'locations',
            ],
            'AdministrationMembership' => [
                'id',
                'status',
                'revoked_at',
            ],
            'AdministrationUserRole' => [
                'id',
                'name',
                'system_key',
                'status',
            ],
            'AdministrationUserLocations' => [
                'plants',
                'areas',
            ],
            'AdministrationUserPlant' => [
                'id',
                'name',
                'code',
                'status',
            ],
            'AdministrationUserArea' => [
                'id',
                'name',
                'code',
                'status',
                'plant_id',
            ],
            'AdministrationUserResponse' => [
                'data',
            ],
            'AdministrationUsersResponse' => [
                'data',
                'meta',
            ],
        ];

        foreach ($expectedSchemas as $name => $fields) {
            $this->assertArrayHasKey($name, $schemas);

            $schema = $schemas[$name];

            $this->assertSame('object', $schema['type']);

            $this->assertEqualsCanonicalizing(
                $fields,
                $schema['required']
            );

            $this->assertEqualsCanonicalizing(
                $fields,
                array_keys($schema['properties'])
            );
        }

        $user = $schemas['AdministrationUser']['properties'];

        $this->assertSame('uuid', $user['id']['format']);
        $this->assertSame('email', $user['email']['format']);
        $this->assertSame('boolean', $user['account_status']['type']);

        $this->assertSame(
            '#/components/schemas/AdministrationMembership',
            $user['membership']['$ref']
        );

        $this->assertSame('array', $user['roles']['type']);

        $this->assertSame(
            '#/components/schemas/AdministrationUserRole',
            $user['roles']['items']['$ref']
        );

        $this->assertSame(
            '#/components/schemas/AdministrationUserLocations',
            $user['locations']['$ref']
        );

        $membership = $schemas['AdministrationMembership']['properties'];

        $this->assertEqualsCanonicalizing(
            ['active', 'revoked'],
            $membership['status']['enum']
        );

        $this->assertTrue(
            $membership['revoked_at']['nullable']
        );

        $this->assertSame(
            'date-time',
            $membership['revoked_at']['format']
        );

        $role = $schemas['AdministrationUserRole']['properties'];

        $this->assertTrue($role['system_key']['nullable']);
        $this->assertSame('boolean', $role['status']['type']);

        $locations = $schemas['AdministrationUserLocations']['properties'];

        $this->assertSame('array', $locations['plants']['type']);
        $this->assertSame('array', $locations['areas']['type']);

        $this->assertSame(
            '#/components/schemas/AdministrationUserPlant',
            $locations['plants']['items']['$ref']
        );

        $this->assertSame(
            '#/components/schemas/AdministrationUserArea',
            $locations['areas']['items']['$ref']
        );

        $this->assertSame(
            '#/components/schemas/AdministrationUser',
            $schemas['AdministrationUserResponse']['properties']['data']['$ref']
        );

        $list = $schemas['AdministrationUsersResponse']['properties'];

        $this->assertSame('array', $list['data']['type']);

        $this->assertSame(
            '#/components/schemas/AdministrationUser',
            $list['data']['items']['$ref']
        );

        $this->assertSame(
            '#/components/schemas/PaginationMeta',
            $list['meta']['$ref']
        );
    }
}
