<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function assignPermissions(
        User $user,
        Organization $organization,
        string $roleName,
        array $permissions
    ): void {
        app(TenantContext::class)->set($organization->id);

        $role = $this->role($organization, $roleName);

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

        app(TenantContext::class)->clear();
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

    private function createIncident(
        User $reporter,
        Organization $organization,
        string $plantId,
        string $areaId,
        array $attributes = []
    ): Incident {
        return app(TenantContext::class)->runAs(
            $organization->id,
            fn () => Incident::create(array_merge([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->id,
                'reported_by' => $reporter->id,
                'plant_id' => $plantId,
                'area_id' => $areaId,
                'type' => 'unsafe_condition',
                'title' => 'Incidencia de prueba',
                'description' => 'Descripción de prueba',
                'risk_level' => 'medium',
                'status' => 'open',
                'reported_at' => '2026-11-15 12:00:00',
            ], $attributes))
        );
    }

    public function test_dashboard_requires_authentication(): void
    {
        $organization = $this->clientOrganization('DASH-AUTH');

        $this->withHeader(
            'X-Organization-Id',
            $organization->id
        )
            ->getJson('/api/dashboard')
            ->assertUnauthorized();
    }

    public function test_dashboard_requires_organization(): void
    {
        $organization = $this->clientOrganization('DASH-ORG');

        $user = $this->user($organization);

        $this->actingAsApi($user)
            ->getJson('/api/dashboard')
            ->assertStatus(400)
            ->assertJsonPath(
                'code',
                'organization_required'
            );
    }

    public function test_dashboard_denies_foreign_organization(): void
    {
        $organizationA = $this->clientOrganization('DASH-OWN');

        $organizationB = $this->clientOrganization('DASH-FOREIGN');

        $user = $this->user($organizationA);

        $this->actingAsApiInOrganization(
            $user,
            $organizationB
        )
            ->getJson('/api/dashboard')
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'organization_access_denied'
            );
    }

    public function test_dashboard_rejects_invalid_month(): void
    {
        $organization = $this->clientOrganization('DASH-INVALID');

        $user = $this->user($organization);

        $this->actingAsApiInOrganization(
            $user,
            $organization
        )
            ->getJson('/api/dashboard?month=2026-13')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');
    }

    public function test_dashboard_uses_current_month_by_default(): void
    {
        $this->travelTo(
            now()->setDate(2026, 11, 15)->startOfDay()
        );

        $organization = $this->clientOrganization('DASH-DEFAULT');

        $user = $this->user($organization);

        $this->actingAsApiInOrganization(
            $user,
            $organization
        )
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('period.month', '2026-11')
            ->assertJsonPath('period.date_from', '2026-11-01')
            ->assertJsonPath('period.date_to', '2026-11-30')
            ->assertJsonPath(
                'period.previous_date_from',
                '2026-10-01'
            )
            ->assertJsonPath(
                'period.previous_date_to',
                '2026-10-31'
            );
    }

    public function test_user_without_permissions_receives_no_statistics(): void
    {
        $organization = $this->clientOrganization('DASH-EMPTY');

        $user = $this->user($organization);

        $response = $this->actingAsApiInOrganization(
            $user,
            $organization
        )
            ->getJson('/api/dashboard?month=2026-11')
            ->assertOk()
            ->assertJsonPath(
                'organization_id',
                $organization->id
            )
            ->assertJsonPath('capabilities', [
                'inspections' => false,
                'incidents' => false,
                'corrective_actions' => false,
                'create_inspection' => false,
            ])
            ->assertJsonPath('recent_inspections', [])
            ->assertJsonPath('pending_corrective_actions', []);

        $this->assertSame(
            [],
            $response->json('summary')
        );

        $this->assertSame(
            [],
            $response->json('charts')
        );
    }

    public function test_inspection_permissions_limit_dashboard_statistics(): void
    {
        $organization = $this->clientOrganization('DASH-INSPECT');

        $user = $this->user($organization);

        $this->assignPermissions(
            $user,
            $organization,
            'Supervisor Dashboard',
            [
                Permission::InspectionsManage,
            ]
        );

        $this->actingAsApiInOrganization(
            $user,
            $organization
        )
            ->getJson('/api/dashboard?month=2026-11')
            ->assertOk()
            ->assertJsonPath('capabilities.inspections', true)
            ->assertJsonPath('capabilities.incidents', false)
            ->assertJsonPath('capabilities.corrective_actions', false)
            ->assertJsonPath('capabilities.create_inspection', false)
            ->assertJsonPath('summary.inspections.value', 0)
            ->assertJsonPath('summary.approval_rate.value', null)
            ->assertJsonCount(5, 'charts.approval_trend')
            ->assertJsonMissingPath('summary.open_incidents')
            ->assertJsonMissingPath('summary.pending_corrective_actions')
            ->assertJsonMissingPath('charts.incidents_by_risk')
            ->assertJsonPath('pending_corrective_actions', []);
    }

    public function test_authorized_user_receives_all_dashboard_sections(): void
    {
        $organization = $this->clientOrganization('DASH-FULL');

        $user = $this->user($organization);

        $this->assignPermissions(
            $user,
            $organization,
            'Gestor Dashboard',
            [
                Permission::InspectionsManage,
                Permission::InspectionsExecute,
                Permission::FindingsRegister,
                Permission::FindingsClose,
                Permission::CorrectiveActionsManage,
            ]
        );

        $this->actingAsApiInOrganization(
            $user,
            $organization
        )
            ->getJson('/api/dashboard?month=2026-11')
            ->assertOk()
            ->assertJsonPath('capabilities', [
                'inspections' => true,
                'incidents' => true,
                'corrective_actions' => true,
                'create_inspection' => true,
            ])
            ->assertJsonPath('summary.approval_rate.value', null)
            ->assertJsonPath('summary.approval_rate.reviewed', 0)
            ->assertJsonPath('summary.inspections.value', 0)
            ->assertJsonPath('summary.open_incidents.value', 0)
            ->assertJsonPath(
                'summary.pending_corrective_actions.value',
                0
            )
            ->assertJsonCount(5, 'charts.approval_trend')
            ->assertJsonCount(4, 'charts.incidents_by_risk')
            ->assertJsonPath(
                'charts.approval_trend.0.month',
                '2026-07'
            )
            ->assertJsonPath(
                'charts.approval_trend.4.month',
                '2026-11'
            )
            ->assertJsonPath('recent_inspections', [])
            ->assertJsonPath('pending_corrective_actions', []);
    }

    public function test_dashboard_permissions_are_isolated_by_organization(): void
    {
        $organizationA = $this->clientOrganization('DASH-SWITCH-A');

        $organizationB = $this->clientOrganization('DASH-SWITCH-B');

        $user = $this->user($organizationA);

        OrganizationMembership::firstOrCreate([
            'user_id' => $user->id,
            'organization_id' => $organizationB->id,
        ], [
            'id' => (string) Str::uuid7(),
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->assignPermissions(
            $user,
            $organizationA,
            'Gestor Dashboard A',
            [
                Permission::InspectionsManage,
                Permission::FindingsClose,
                Permission::CorrectiveActionsManage,
            ]
        );

        $this->assignPermissions(
            $user,
            $organizationB,
            'Supervisor Dashboard B',
            [
                Permission::InspectionsManage,
            ]
        );

        $this->actingAsApiInOrganization(
            $user,
            $organizationA
        )
            ->getJson('/api/dashboard?month=2026-11')
            ->assertOk()
            ->assertJsonPath(
                'organization_id',
                $organizationA->id
            )
            ->assertJsonPath('capabilities.inspections', true)
            ->assertJsonPath('capabilities.incidents', true)
            ->assertJsonPath('capabilities.corrective_actions', true);

        $this->withHeader(
            'X-Organization-Id',
            $organizationB->id
        )
            ->getJson('/api/dashboard?month=2026-11')
            ->assertOk()
            ->assertJsonPath(
                'organization_id',
                $organizationB->id
            )
            ->assertJsonPath('capabilities.inspections', true)
            ->assertJsonPath('capabilities.incidents', false)
            ->assertJsonPath('capabilities.corrective_actions', false)
            ->assertJsonMissingPath('summary.open_incidents')
            ->assertJsonMissingPath('summary.pending_corrective_actions')
            ->assertJsonMissingPath('charts.incidents_by_risk');
    }

    public function test_dashboard_excludes_incidents_from_unauthorized_locations_and_tenants(): void
    {
        $organizationA = $this->clientOrganization('DASH-DATA-A');

        $organizationB = $this->clientOrganization('DASH-DATA-B');

        $user = $this->user($organizationA);

        $foreignUser = $this->user($organizationB);

        $this->assignPermissions(
            $user,
            $organizationA,
            'Gestor Incidencias Dashboard',
            [
                Permission::FindingsRegister,
            ]
        );

        $allowedPlant = $this->plant(
            $organizationA,
            'DASH-P1'
        );

        $allowedArea = $this->area(
            $allowedPlant,
            'DASH-A1'
        );

        $restrictedPlant = $this->plant(
            $organizationA,
            'DASH-P2'
        );

        $restrictedArea = $this->area(
            $restrictedPlant,
            'DASH-A2'
        );

        $foreignPlant = $this->plant(
            $organizationB,
            'DASH-P3'
        );

        $foreignArea = $this->area(
            $foreignPlant,
            'DASH-A3'
        );

        $this->assignPlant(
            $user,
            $organizationA,
            $allowedPlant->id
        );

        $this->createIncident(
            $user,
            $organizationA,
            $allowedPlant->id,
            $allowedArea->id,
            [
                'risk_level' => 'critical',
            ]
        );

        $this->createIncident(
            $user,
            $organizationA,
            $restrictedPlant->id,
            $restrictedArea->id,
            [
                'risk_level' => 'critical',
            ]
        );

        $this->createIncident(
            $foreignUser,
            $organizationB,
            $foreignPlant->id,
            $foreignArea->id,
            [
                'risk_level' => 'critical',
            ]
        );

        $this->actingAsApiInOrganization(
            $user,
            $organizationA
        )
            ->getJson('/api/dashboard?month=2026-11')
            ->assertOk()
            ->assertJsonPath('summary.open_incidents.value', 1)
            ->assertJsonPath('summary.open_incidents.critical', 1)
            ->assertJsonPath('charts.incidents_by_risk', [
                [
                    'risk_level' => 'low',
                    'value' => 0,
                ],
                [
                    'risk_level' => 'medium',
                    'value' => 0,
                ],
                [
                    'risk_level' => 'high',
                    'value' => 0,
                ],
                [
                    'risk_level' => 'critical',
                    'value' => 1,
                ],
            ]);
    }

    public function test_dashboard_filters_incident_chart_by_month(): void
    {
        $organization = $this->clientOrganization('DASH-MONTH');

        $user = $this->user($organization);

        $this->assignPermissions(
            $user,
            $organization,
            'Gestor Periodos Dashboard',
            [
                Permission::FindingsRegister,
            ]
        );

        $plant = $this->plant(
            $organization,
            'DASH-MP'
        );

        $area = $this->area(
            $plant,
            'DASH-MA'
        );

        $this->assignPlant(
            $user,
            $organization,
            $plant->id
        );

        $this->createIncident(
            $user,
            $organization,
            $plant->id,
            $area->id,
            [
                'risk_level' => 'low',
                'reported_at' => '2026-10-31 23:59:59',
            ]
        );

        $this->createIncident(
            $user,
            $organization,
            $plant->id,
            $area->id,
            [
                'risk_level' => 'high',
                'reported_at' => '2026-11-01 00:00:00',
            ]
        );

        $this->createIncident(
            $user,
            $organization,
            $plant->id,
            $area->id,
            [
                'risk_level' => 'critical',
                'reported_at' => '2026-12-01 00:00:00',
            ]
        );

        $this->actingAsApiInOrganization(
            $user,
            $organization
        )
            ->getJson('/api/dashboard?month=2026-11')
            ->assertOk()
            ->assertJsonPath('summary.open_incidents.value', 3)
            ->assertJsonPath('charts.incidents_by_risk', [
                [
                    'risk_level' => 'low',
                    'value' => 0,
                ],
                [
                    'risk_level' => 'medium',
                    'value' => 0,
                ],
                [
                    'risk_level' => 'high',
                    'value' => 1,
                ],
                [
                    'risk_level' => 'critical',
                    'value' => 0,
                ],
            ]);
    }
}
