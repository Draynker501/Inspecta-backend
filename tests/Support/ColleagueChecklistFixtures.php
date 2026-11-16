<?php

namespace Tests\Support;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/* Adaptado de Diego Castaneda, origin/api 807140e. */
trait ColleagueChecklistFixtures
{
    protected function loginForChecklistTests(
        Organization $organization,
        User $user
    ): void {
        app(TenantContext::class)->runAs($organization->id, function () use ($organization, $user): void {
            $role = $this->role($organization, 'ehs-'.Str::random(12));
            $user->assignRole($role);
            foreach ([Permission::ChecklistsView, Permission::ChecklistsManage] as $permission) {
                RolePermission::create([
                    'id' => (string) Str::uuid7(),
                    'role_id' => $role->id,
                    'organization_id' => $organization->id,
                    'permission' => $permission->value,
                ]);
            }
        });

        $this->actingAsApiInOrganization($user, $organization);
    }

    protected function createChecklist(
        string $name = 'Checklist de seguridad'
    ): string {
        $response = $this->postJson('/api/checklists', [
            'name' => $name,
        ]);

        $response->assertCreated();

        return $response->json('data.id');
    }

    protected function createVersion(
        string $checklistId,
        string $number = '1.0'
    ): string {
        $response = $this->postJson(
            "/api/checklists/{$checklistId}/versions",
            ['version' => $number]
        );

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        return $response->json('data.id');
    }

    protected function createSection(
        string $versionId,
        string $name = 'Seguridad general',
        ?int $order = null
    ): string {
        $data = ['name' => $name];

        if ($order !== null) {
            $data['display_order'] = $order;
        }

        $response = $this->postJson(
            "/api/versions/{$versionId}/sections",
            $data
        );

        $response->assertCreated();

        return $response->json('data.id');
    }

    protected function createQuestion(
        string $sectionId,
        string $text = '¿Se utiliza equipo de protección?',
        ?int $order = null
    ): string {
        $data = [
            'question_text' => $text,
            'answer_type' => 'boolean',
        ];

        if ($order !== null) {
            $data['display_order'] = $order;
        }

        $response = $this->postJson(
            "/api/sections/{$sectionId}/questions",
            $data
        );

        $response->assertCreated();

        return $response->json('data.id');
    }

    protected function publishVersion(
        string $checklistId,
        string $versionId
    ): void {
        $this->patchJson(
            "/api/checklists/{$checklistId}/versions/{$versionId}",
            ['status' => 'published']
        )->assertOk();
    }
}
