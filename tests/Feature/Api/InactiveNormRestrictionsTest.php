<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class InactiveNormRestrictionsTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function prepareUser(Organization $organization): User
    {
        $user = $this->user($organization);

        app(TenantContext::class)->set($organization->id);

        $role = $this->role(
            $organization,
            'Gestor de normativa'
        );

        $user->assignRole($role);

        foreach ([
            Permission::NormsView->value,
            Permission::NormsManage->value,
        ] as $permission) {
            RolePermission::firstOrCreate([
                'role_id' => $role->id,
                'organization_id' => $organization->id,
                'permission' => $permission,
            ], [
                'id' => (string) Str::uuid7(),
            ]);
        }

        return $user;
    }

    private function prepareCatalog(): array
    {
        $organization = $this->clientOrganization();

        $user = $this->prepareUser($organization);

        $this->actingAsApiInOrganization(
            $user,
            $organization
        );

        $normId = $this->postJson('/api/norms', [
            'code' => 'NORM-TEST',
            'name' => 'Norma de prueba',
            'version' => '2026',
        ])
            ->assertCreated()
            ->json('data.id');

        $requirementId = $this->postJson(
            "/api/norms/{$normId}/requirements",
            [
                'code' => '1.1',
                'section' => 'Pruebas',
                'description' => 'Descripción original.',
            ]
        )
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/norms/{$normId}", [
            'status' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', false);

        return [$normId, $requirementId];
    }

    public function test_inactive_norm_remains_readable(): void
    {
        [$normId, $requirementId] = $this->prepareCatalog();

        $this->getJson('/api/norms')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $normId,
                'status' => false,
            ]);

        $this->getJson("/api/norms/{$normId}")
            ->assertOk()
            ->assertJsonPath('data.status', false);

        $this->getJson("/api/norms/{$normId}/requirements")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/requirements/{$requirementId}")
            ->assertOk()
            ->assertJsonPath('data.id', $requirementId);
    }

    public function test_inactive_norm_cannot_be_updated(): void
    {
        [$normId] = $this->prepareCatalog();

        $this->putJson("/api/norms/{$normId}", [
            'code' => 'NORM-TEST',
            'name' => 'Nombre modificado',
            'version' => '2026',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('norms', [
            'id' => $normId,
            'name' => 'Norma de prueba',
            'status' => false,
        ]);
    }

    public function test_requirements_cannot_be_created_for_inactive_norms(): void
    {
        [$normId] = $this->prepareCatalog();

        $this->postJson(
            "/api/norms/{$normId}/requirements",
            [
                'code' => '1.2',
                'description' => 'Nuevo requisito.',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseMissing('norm_requirements', [
            'norm_id' => $normId,
            'code' => '1.2',
        ]);
    }

    public function test_requirements_of_inactive_norms_cannot_be_updated(): void
    {
        [, $requirementId] = $this->prepareCatalog();

        $this->putJson(
            "/api/requirements/{$requirementId}",
            [
                'code' => '1.1',
                'description' => 'Descripción modificada.',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('norm_requirements', [
            'id' => $requirementId,
            'description' => 'Descripción original.',
        ]);
    }

    public function test_reactivation_restores_allowed_write_operations(): void
    {
        [$normId, $requirementId] = $this->prepareCatalog();

        $this->patchJson("/api/norms/{$normId}", [
            'status' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', true);

        $this->putJson("/api/norms/{$normId}", [
            'code' => 'NORM-TEST',
            'name' => 'Norma reactivada',
            'version' => '2026',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Norma reactivada'
            );

        $this->postJson(
            "/api/norms/{$normId}/requirements",
            [
                'code' => '1.2',
                'description' => 'Nuevo requisito.',
            ]
        )
            ->assertCreated();

        $this->putJson(
            "/api/requirements/{$requirementId}",
            [
                'description' => 'Descripción modificada.',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.description',
                'Descripción modificada.'
            );

        $this->assertDatabaseHas('norms', [
            'id' => $normId,
            'name' => 'Norma reactivada',
            'status' => true,
        ]);

        $this->assertDatabaseHas('norm_requirements', [
            'norm_id' => $normId,
            'code' => '1.2',
        ]);

        $this->assertDatabaseHas('norm_requirements', [
            'id' => $requirementId,
            'description' => 'Descripción modificada.',
        ]);
    }
}
