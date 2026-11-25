<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistVersion;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Inspection;
use App\Models\Organization;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const ENDPOINT =
        '/api/reports/summary';

    private const PERIOD =
        '?date_from=2026-11-01&date_to=2026-11-30';

    private function assignPermissions(
        User $user,
        Organization $organization,
        array $permissions
    ): void {
        app(TenantContext::class)->runAs(
            $organization->id,
            function () use (
                $user,
                $organization,
                $permissions
            ): void {
                $role = $this->role(
                    $organization,
                    'Reportes '.Str::uuid7()
                );

                $user->assignRole($role);

                foreach ($permissions as $permission) {
                    RolePermission::firstOrCreate([
                        'role_id' => $role->id,
                        'organization_id' => $organization->id,
                        'permission' => $permission->value,
                    ], [
                        'id' => (string) Str::uuid7(),
                    ]);
                }
            }
        );
    }

    private function assignPlant(
        User $user,
        Organization $organization,
        string $plantId
    ): void {
        DB::table('user_plants')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'plant_id' => $plantId,
        ]);
    }

    private function assignArea(
        User $user,
        Organization $organization,
        string $areaId
    ): void {
        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'area_id' => $areaId,
        ]);
    }

    private function prepareFixture(
        string $code,
        array $permissions = [],
        bool $assignLocation = true
    ): array {
        $organization = $this->clientOrganization($code);

        $user = $this->user($organization);

        $otherUser = $this->user($organization);

        $plant = $this->plant(
            $organization,
            "{$code}-P"
        );

        $area = $this->area(
            $plant,
            "{$code}-A"
        );

        $line = $this->productionLine(
            $area,
            "{$code}-L"
        );

        if ($assignLocation) {
            $this->assignPlant(
                $user,
                $organization,
                $plant->id
            );
        }

        if ($permissions !== []) {
            $this->assignPermissions(
                $user,
                $organization,
                $permissions
            );
        }

        $version = app(TenantContext::class)->runAs(
            $organization->id,
            function () use ($organization, $user) {
                $checklist = Checklist::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->id,
                    'name' => 'Checklist de reportes',
                    'status' => true,
                ]);

                return ChecklistVersion::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->id,
                    'checklist_id' => $checklist->id,
                    'version' => '1.0',
                    'status' => 'draft',
                    'created_by' => $user->id,
                ]);
            }
        );

        return [
            'organization' => $organization,
            'user' => $user,
            'other_user' => $otherUser,
            'plant' => $plant,
            'area' => $area,
            'line' => $line,
            'version' => $version,
        ];
    }

    private function authenticate(array $fixture): void
    {
        $this->actingAsApiInOrganization(
            $fixture['user'],
            $fixture['organization']
        );
    }

    private function createInspection(
        array $fixture,
        array $attributes = []
    ): Inspection {
        return app(TenantContext::class)->runAs(
            $fixture['organization']->id,
            fn () => Inspection::create(array_merge([
                'id' => (string) Str::uuid7(),
                'organization_id' => $fixture['organization']->id,
                'user_id' => $fixture['user']->id,
                'checklist_version_id' => $fixture['version']->id,
                'plant_id' => $fixture['plant']->id,
                'area_id' => $fixture['area']->id,
                'production_line_id' => $fixture['line']->id,
                'status' => 'draft',
                'started_at' => '2026-11-15 10:00:00',
            ], $attributes))
        );
    }

    private function createIncident(
        array $fixture,
        array $attributes = []
    ): Incident {
        return app(TenantContext::class)->runAs(
            $fixture['organization']->id,
            fn () => Incident::create(array_merge([
                'id' => (string) Str::uuid7(),
                'organization_id' => $fixture['organization']->id,
                'reported_by' => $fixture['user']->id,
                'plant_id' => $fixture['plant']->id,
                'area_id' => $fixture['area']->id,
                'production_line_id' => $fixture['line']->id,
                'type' => 'unsafe_condition',
                'title' => 'Incidencia de reportes',
                'description' => 'Descripción de prueba',
                'risk_level' => 'medium',
                'status' => 'open',
                'reported_at' => '2026-11-15 12:00:00',
            ], $attributes))
        );
    }

    private function createAction(
        array $fixture,
        Incident $incident,
        array $attributes = []
    ): CorrectiveAction {
        return app(TenantContext::class)->runAs(
            $fixture['organization']->id,
            function () use (
                $fixture,
                $incident,
                $attributes
            ): CorrectiveAction {
                $action = CorrectiveAction::create(
                    array_merge([
                        'id' => (string) Str::uuid7(),
                        'organization_id' => $fixture['organization']->id,
                        'incident_id' => $incident->id,
                        'assigned_to' => $fixture['user']->id,
                        'description' => 'Acción de prueba',
                        'priority' => 'medium',
                        'status' => 'pending',
                        'due_date' => '2026-11-30',
                    ], $attributes)
                );

                $action->forceFill([
                    'created_at' => '2026-11-15 13:00:00',
                ])->save();

                return $action;
            }
        );
    }

    public function test_report_requires_authentication(): void
    {
        $this->getJson(self::ENDPOINT.self::PERIOD)
            ->assertUnauthorized();
    }

    public function test_report_requires_organization(): void
    {
        $organization = $this->clientOrganization(
            'REPORT-ORG'
        );

        $user = $this->user($organization);

        $this->actingAsApi($user)
            ->getJson(self::ENDPOINT.self::PERIOD)
            ->assertStatus(400)
            ->assertJsonPath(
                'code',
                'organization_required'
            );
    }

    public function test_report_denies_foreign_organization(): void
    {
        $organization = $this->clientOrganization(
            'REPORT-OWN'
        );

        $foreign = $this->clientOrganization(
            'REPORT-FOREIGN'
        );

        $user = $this->user($organization);

        $this->actingAsApiInOrganization($user, $foreign)
            ->getJson(self::ENDPOINT.self::PERIOD)
            ->assertForbidden();
    }

    public function test_report_requires_report_permission(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-DENIED',
            [
                Permission::InspectionsManage,
                Permission::FindingsRegister,
                Permission::CorrectiveActionsManage,
            ]
        );

        $this->authenticate($fixture);

        $this->getJson(self::ENDPOINT.self::PERIOD)
            ->assertForbidden();
    }

    public function test_report_requires_dates(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-DATES',
            [Permission::ReportsView]
        );

        $this->authenticate($fixture);

        $this->getJson(self::ENDPOINT)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'date_from',
                'date_to',
            ]);
    }

    public function test_report_rejects_invalid_period(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-INVALID',
            [Permission::ReportsView]
        );

        $this->authenticate($fixture);

        $this->getJson(
            self::ENDPOINT
            .'?date_from=2026-11-30&date_to=2026-11-01'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');

        $this->getJson(
            self::ENDPOINT
            .'?date_from=2026-02-30&date_to=2026-11-30'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');
    }

    public function test_report_rejects_unknown_parameters(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-UNKNOWN',
            [Permission::ReportsView]
        );

        $this->authenticate($fixture);

        $this->getJson(
            self::ENDPOINT.self::PERIOD.'&unexpected=true'
        )->assertUnprocessable();
    }

    public function test_report_hides_unauthorized_sections(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-HIDDEN',
            [Permission::ReportsView]
        );

        $this->authenticate($fixture);

        $this->getJson(self::ENDPOINT.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('scope', 'all')
            ->assertJsonPath('capabilities', [
                'inspections' => false,
                'incidents' => false,
                'corrective_actions' => false,
            ])
            ->assertJsonPath('inspections', null)
            ->assertJsonPath('incidents', null)
            ->assertJsonPath('corrective_actions', null);
    }

    public function test_report_returns_zero_counts_and_null_rates(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-EMPTY',
            [
                Permission::ReportsView,
                Permission::InspectionsManage,
                Permission::FindingsRegister,
                Permission::CorrectiveActionsManage,
            ]
        );

        $this->authenticate($fixture);

        $this->getJson(self::ENDPOINT.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('capabilities', [
                'inspections' => true,
                'incidents' => true,
                'corrective_actions' => true,
            ])
            ->assertJsonPath('inspections.total', 0)
            ->assertJsonPath(
                'inspections.completion_rate',
                null
            )
            ->assertJsonPath(
                'inspections.approval_rate',
                null
            )
            ->assertJsonPath('incidents.total', 0)
            ->assertJsonPath('incidents.by_area', [])
            ->assertJsonPath('corrective_actions.total', 0)
            ->assertJsonPath(
                'corrective_actions.verification_rate',
                null
            );
    }

    public function test_report_calculates_inspection_statistics(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-INSPECTIONS',
            [
                Permission::ReportsView,
                Permission::InspectionsManage,
            ]
        );

        foreach ([
            'draft',
            'completed',
            'confirmed',
            'confirmed',
            'rejected',
        ] as $status) {
            $this->createInspection($fixture, [
                'status' => $status,
                'completed_at' => $status !== 'draft'
                    ? '2026-11-15 11:00:00'
                    : null,
                'supervisor_id' => in_array(
                    $status,
                    ['confirmed', 'rejected'],
                    true
                ) ? $fixture['other_user']->id : null,
                'supervisor_confirmed_at' => $status === 'confirmed'
                        ? '2026-11-16 10:00:00'
                        : null,
                'rejected_at' => $status === 'rejected'
                    ? '2026-11-16 10:00:00'
                    : null,
            ]);
        }

        $this->authenticate($fixture);

        $this->getJson(self::ENDPOINT.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('inspections.total', 5)
            ->assertJsonPath('inspections.finalized', 4)
            ->assertJsonPath('inspections.reviewed', 3)
            ->assertJsonPath(
                'inspections.completion_rate',
                80
            )
            ->assertJsonPath(
                'inspections.approval_rate',
                66.67
            )
            ->assertJsonPath('inspections.by_status', [
                'draft' => 1,
                'completed' => 1,
                'confirmed' => 2,
                'rejected' => 1,
            ]);
    }

    public function test_report_calculates_incident_statistics(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-INCIDENTS',
            [
                Permission::ReportsView,
                Permission::FindingsRegister,
            ]
        );

        foreach ([
            ['low', 'open'],
            ['medium', 'in_progress'],
            ['high', 'resolved'],
            ['critical', 'closed'],
        ] as [$risk, $status]) {
            $this->createIncident($fixture, [
                'risk_level' => $risk,
                'status' => $status,
            ]);
        }

        $this->authenticate($fixture);

        $this->getJson(self::ENDPOINT.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('incidents.total', 4)
            ->assertJsonPath('incidents.critical', 1)
            ->assertJsonPath('incidents.open', 2)
            ->assertJsonPath('incidents.resolved', 2)
            ->assertJsonPath('incidents.by_risk_level', [
                'low' => 1,
                'medium' => 1,
                'high' => 1,
                'critical' => 1,
            ])
            ->assertJsonCount(1, 'incidents.by_area')
            ->assertJsonPath(
                'incidents.by_area.0.area_id',
                $fixture['area']->id
            )
            ->assertJsonPath(
                'incidents.by_area.0.area_name',
                $fixture['area']->name
            )
            ->assertJsonPath(
                'incidents.by_area.0.count',
                4
            )
            ->assertJsonPath(
                'incidents.by_area.0.percentage',
                100
            );
    }

    public function test_report_calculates_corrective_action_statistics(): void
    {
        $this->travelTo(
            now()->setDate(2026, 11, 25)->startOfDay()
        );

        $fixture = $this->prepareFixture(
            'REPORT-ACTIONS',
            [
                Permission::ReportsView,
                Permission::CorrectiveActionsManage,
            ]
        );

        $incident = $this->createIncident($fixture);

        foreach ([
            ['pending', '2026-11-20'],
            ['in_progress', '2026-11-25'],
            ['completed', '2026-11-20'],
            ['verified', '2026-11-20'],
        ] as [$status, $dueDate]) {
            $this->createAction($fixture, $incident, [
                'status' => $status,
                'due_date' => $dueDate,
                'completed_at' => in_array(
                    $status,
                    ['completed', 'verified'],
                    true
                ) ? '2026-11-19 10:00:00' : null,
            ]);
        }

        $this->authenticate($fixture);

        $this->getJson(self::ENDPOINT.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('corrective_actions.total', 4)
            ->assertJsonPath('corrective_actions.verified', 1)
            ->assertJsonPath('corrective_actions.pending', 2)
            ->assertJsonPath('corrective_actions.overdue', 1)
            ->assertJsonPath(
                'corrective_actions.verification_rate',
                25
            )
            ->assertJsonPath(
                'corrective_actions.by_status',
                [
                    'pending' => 1,
                    'in_progress' => 1,
                    'completed' => 1,
                    'verified' => 1,
                ]
            );
    }

    public function test_report_includes_both_period_boundaries(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-BOUNDARIES',
            [
                Permission::ReportsView,
                Permission::InspectionsManage,
                Permission::FindingsRegister,
                Permission::CorrectiveActionsManage,
            ]
        );

        foreach ([
            '2026-10-31 23:59:59',
            '2026-11-01 00:00:00',
            '2026-11-30 23:59:59',
            '2026-12-01 00:00:00',
        ] as $date) {
            $this->createInspection($fixture, [
                'started_at' => $date,
            ]);

            $incident = $this->createIncident($fixture, [
                'reported_at' => $date,
            ]);

            $action = $this->createAction(
                $fixture,
                $incident
            );

            app(TenantContext::class)->runAs(
                $fixture['organization']->id,
                fn () => $action->forceFill([
                    'created_at' => $date,
                ])->save()
            );
        }

        $this->authenticate($fixture);

        $this->getJson(self::ENDPOINT.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('inspections.total', 2)
            ->assertJsonPath('incidents.total', 2)
            ->assertJsonPath('corrective_actions.total', 2);
    }

    public function test_report_own_permission_limits_all_resources(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-OWN-SCOPE',
            [
                Permission::ReportsViewOwn,
                Permission::InspectionsManage,
                Permission::FindingsRegister,
                Permission::CorrectiveActionsManage,
            ]
        );

        $this->createInspection($fixture);

        $this->createInspection($fixture, [
            'user_id' => $fixture['other_user']->id,
        ]);

        $ownIncident = $this->createIncident($fixture);

        $otherIncident = $this->createIncident($fixture, [
            'reported_by' => $fixture['other_user']->id,
        ]);

        $this->createAction($fixture, $ownIncident);

        $this->createAction($fixture, $otherIncident, [
            'assigned_to' => $fixture['other_user']->id,
        ]);

        $this->authenticate($fixture);

        $this->getJson(self::ENDPOINT.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('scope', 'own')
            ->assertJsonPath('inspections.total', 1)
            ->assertJsonPath('incidents.total', 1)
            ->assertJsonPath('corrective_actions.total', 1);
    }

    public function test_reports_view_does_not_override_inspection_permissions(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-EXECUTE',
            [
                Permission::ReportsView,
                Permission::InspectionsExecute,
            ]
        );

        $this->createInspection($fixture);

        $this->createInspection($fixture, [
            'user_id' => $fixture['other_user']->id,
        ]);

        $this->authenticate($fixture);

        $this->getJson(self::ENDPOINT.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('scope', 'all')
            ->assertJsonPath('inspections.total', 1)
            ->assertJsonPath('incidents', null)
            ->assertJsonPath('corrective_actions', null);
    }

    public function test_report_excludes_unauthorized_locations_and_tenants(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-LOCATION',
            [
                Permission::ReportsView,
                Permission::InspectionsManage,
                Permission::FindingsRegister,
                Permission::CorrectiveActionsManage,
                Permission::LocationsViewAll,
            ],
            false
        );

        $this->assignArea(
            $fixture['user'],
            $fixture['organization'],
            $fixture['area']->id
        );

        $restrictedArea = $this->area(
            $fixture['plant'],
            'REPORT-RESTRICTED'
        );

        $this->createInspection($fixture);

        $this->createInspection($fixture, [
            'area_id' => $restrictedArea->id,
            'production_line_id' => null,
        ]);

        $allowedIncident = $this->createIncident($fixture);

        $restrictedIncident = $this->createIncident(
            $fixture,
            [
                'area_id' => $restrictedArea->id,
                'production_line_id' => null,
            ]
        );

        $this->createAction($fixture, $allowedIncident);
        $this->createAction($fixture, $restrictedIncident);

        $foreign = $this->prepareFixture(
            'REPORT-OTHER-TENANT'
        );

        $this->createInspection($foreign);

        $foreignIncident = $this->createIncident($foreign);

        $this->createAction($foreign, $foreignIncident);

        $this->authenticate($fixture);

        $this->getJson(self::ENDPOINT.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('inspections.total', 1)
            ->assertJsonPath('incidents.total', 1)
            ->assertJsonPath('corrective_actions.total', 1);
    }

    public function test_report_filters_by_plant_area_and_line(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-FILTERS',
            [
                Permission::ReportsView,
                Permission::InspectionsManage,
                Permission::FindingsRegister,
                Permission::CorrectiveActionsManage,
            ]
        );

        $secondArea = $this->area(
            $fixture['plant'],
            'REPORT-SECOND-AREA'
        );

        $this->createInspection($fixture);

        $this->createInspection($fixture, [
            'area_id' => $secondArea->id,
            'production_line_id' => null,
        ]);

        $firstIncident = $this->createIncident($fixture);

        $secondIncident = $this->createIncident(
            $fixture,
            [
                'area_id' => $secondArea->id,
                'production_line_id' => null,
            ]
        );

        $this->createAction($fixture, $firstIncident);
        $this->createAction($fixture, $secondIncident);

        $this->authenticate($fixture);

        $this->getJson(
            self::ENDPOINT.self::PERIOD
            .'&plant_id='.$fixture['plant']->id
        )
            ->assertOk()
            ->assertJsonPath('inspections.total', 2)
            ->assertJsonPath('incidents.total', 2)
            ->assertJsonPath('corrective_actions.total', 2);

        $this->getJson(
            self::ENDPOINT.self::PERIOD
            .'&area_id='.$fixture['area']->id
        )
            ->assertOk()
            ->assertJsonPath('inspections.total', 1)
            ->assertJsonPath('incidents.total', 1)
            ->assertJsonPath('corrective_actions.total', 1);

        $this->getJson(
            self::ENDPOINT.self::PERIOD
            .'&production_line_id='.$fixture['line']->id
        )
            ->assertOk()
            ->assertJsonPath('inspections.total', 1)
            ->assertJsonPath('incidents.total', 1)
            ->assertJsonPath('corrective_actions.total', 1);
    }

    public function test_report_rejects_foreign_and_inconsistent_filters(): void
    {
        $fixture = $this->prepareFixture(
            'REPORT-VALIDATION',
            [Permission::ReportsView]
        );

        $foreign = $this->prepareFixture(
            'REPORT-FOREIGN-FILTER'
        );

        $secondPlant = $this->plant(
            $fixture['organization'],
            'REPORT-SECOND-PLANT'
        );

        $secondArea = $this->area(
            $secondPlant,
            'REPORT-INCOMPATIBLE-AREA'
        );

        $this->authenticate($fixture);

        $this->getJson(
            self::ENDPOINT.self::PERIOD
            .'&area_id='.$foreign['area']->id
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('area_id');

        $this->getJson(
            self::ENDPOINT.self::PERIOD
            .'&plant_id='.$fixture['plant']->id
            .'&area_id='.$secondArea->id
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('area_id');

        $this->getJson(
            self::ENDPOINT.self::PERIOD
            .'&area_id='.$secondArea->id
            .'&production_line_id='.$fixture['line']->id
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'production_line_id'
            );
    }
}
