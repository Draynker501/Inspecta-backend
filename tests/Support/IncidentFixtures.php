<?php

namespace Tests\Support;

use App\Models\Inspection;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

trait IncidentFixtures
{
    use TenantFixtures;
    use ChecklistFixtures;

    protected function prepareIncidentEnvironment(
        string $code = 'INC-A'
    ): array {
        $organization = $this->organization($code);
        $user = $this->user($organization);

        $this->actingAsApiInOrganization($user, $organization);

        $plant = $this->plant($organization, "PL-{$code}");
        $area = $this->area($plant, "AR-{$code}");

        return compact('organization', 'user', 'plant', 'area');
    }

    protected function createInspectionForEvidence(
        array $environment
    ): Inspection {
        $checklistId = $this->createChecklist();
        $versionId = $this->createVersion($checklistId);
        $sectionId = $this->createSection($versionId);

        $this->createQuestion($sectionId);

        $this->publishVersion($checklistId, $versionId);

        return Inspection::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $environment['organization']->id,
            'user_id' => $environment['user']->id,
            'checklist_version_id' => $versionId,
            'plant_id' => $environment['plant']->id,
            'area_id' => $environment['area']->id,
            'status' => 'draft',
            'started_at' => now(),
        ]);
    }

    protected function createIncidentForTests(array $environment,?string $inspectionId = null): string 
    {
        $payload = [
            'plant_id' => $environment['plant']->id,
            'area_id' => $environment['area']->id,
            'title' => 'Extintor en mal estado',
            'description' => 'Se detectó un extintor caducado.',
            'risk_level' => 'high',
            'type' => 'unsafe_condition',
        ];

        if ($inspectionId !== null) {
            $payload['inspection_id'] = $inspectionId;
        }

        $response = $this->postJson('/api/incidents', $payload);

        $response->assertCreated();

        return $response->json('data.id');
    }

    protected function createCorrectiveActionForTests(
        string $incidentId,
        User $assignedTo
    ): string {
        $response = $this->postJson('/api/corrective-actions', [
            'incident_id' => $incidentId,
            'assigned_to' => $assignedTo->id,
            'description' => 'Reemplazar el extintor caducado.',
            'priority' => 'high',
        ]);

        $response->assertCreated();

        return $response->json('data.id');
    }

    protected function switchIncidentOrganization(
        array $environment
    ): void {
        app(TenantContext::class)->clear();

        $this->actingAsApiInOrganization(
            $environment['user'],
            $environment['organization']
        );
    }
}