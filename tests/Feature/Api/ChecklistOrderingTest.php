<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ChecklistOrderingTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function prepareUser(
        Organization $organization
    ): User {
        $user = $this->user($organization);

        app(TenantContext::class)
            ->set($organization->id);

        $role = $this->role(
            $organization,
            'Gestor de checklists'
        );

        $user->assignRole($role);

        foreach ([
            'checklists.view',
            'checklists.manage',
        ] as $permission) {
            RolePermission::create([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->id,
                'role_id' => $role->id,
                'permission' => $permission,
            ]);
        }

        return $user;
    }

    private function createChecklistAndVersion(): array
    {
        $checklist = $this->postJson('/api/checklists', [
            'name' => 'Checklist de pruebas',
            'description' => 'Pruebas de reordenamiento',
        ])->assertCreated()->json('data');

        $version = $this->postJson(
            "/api/checklists/{$checklist['id']}/versions",
            [
                'version' => '1.0',
            ]
        )->assertCreated()->json('data');

        return [$checklist, $version];
    }

    private function createSection(
        string $versionId,
        string $name,
        int $order
    ): array {
        return $this->postJson(
            "/api/versions/{$versionId}/sections",
            [
                'name' => $name,
                'display_order' => $order,
            ]
        )->assertCreated()->json('data');
    }

    private function createQuestion(
        string $sectionId,
        string $text,
        int $order
    ): array {
        return $this->postJson(
            "/api/sections/{$sectionId}/questions",
            [
                'question_text' => $text,
                'answer_type' => 'boolean',
                'display_order' => $order,
                'required' => true,
                'allows_evidence' => false,
            ]
        )->assertCreated()->json('data');
    }

    public function test_reordena_secciones_sin_conflictos_de_unicidad(): void
    {
        $organization = $this->clientOrganization('ORDER-01');
        $user = $this->prepareUser($organization);

        $this->actingAsApiInOrganization(
            $user,
            $organization
        );

        [, $version] = $this->createChecklistAndVersion();

        $a = $this->createSection(
            $version['id'],
            'Sección A',
            1
        );

        $b = $this->createSection(
            $version['id'],
            'Sección B',
            2
        );

        $c = $this->createSection(
            $version['id'],
            'Sección C',
            7
        );

        $this->patchJson(
            "/api/versions/{$version['id']}/sections/reorder",
            [
                'section_ids' => [
                    $b['id'],
                    $a['id'],
                    $c['id'],
                ],
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.0.id', $b['id'])
            ->assertJsonPath('data.0.display_order', 1)
            ->assertJsonPath('data.1.id', $a['id'])
            ->assertJsonPath('data.1.display_order', 2)
            ->assertJsonPath('data.2.id', $c['id'])
            ->assertJsonPath('data.2.display_order', 3);

        $this->assertDatabaseHas('checklist_sections', [
            'id' => $b['id'],
            'display_order' => 1,
        ]);

        $this->assertDatabaseHas('checklist_sections', [
            'id' => $a['id'],
            'display_order' => 2,
        ]);

        $this->assertDatabaseHas('checklist_sections', [
            'id' => $c['id'],
            'display_order' => 3,
        ]);
    }

    public function test_rechaza_listas_incompletas_y_duplicadas(): void
    {
        $organization = $this->clientOrganization('ORDER-02');
        $user = $this->prepareUser($organization);

        $this->actingAsApiInOrganization(
            $user,
            $organization
        );

        [, $version] = $this->createChecklistAndVersion();

        $a = $this->createSection(
            $version['id'],
            'Sección A',
            1
        );

        $b = $this->createSection(
            $version['id'],
            'Sección B',
            5
        );

        $url = "/api/versions/{$version['id']}/sections/reorder";

        // Lista incompleta.
        $this->patchJson($url, [
            'section_ids' => [$a['id']],
        ])->assertStatus(422);

        // Identificadores duplicados.
        $this->patchJson($url, [
            'section_ids' => [
                $a['id'],
                $a['id'],
            ],
        ])->assertStatus(422);

        // Identificador ajeno.
        $this->patchJson($url, [
            'section_ids' => [
                $a['id'],
                (string) Str::uuid7(),
            ],
        ])->assertStatus(422);

        // Verificar que no hubo modificaciones.
        $this->assertDatabaseHas('checklist_sections', [
            'id' => $a['id'],
            'display_order' => 1,
        ]);

        $this->assertDatabaseHas('checklist_sections', [
            'id' => $b['id'],
            'display_order' => 5,
        ]);
    }

    public function test_reordena_preguntas_de_una_seccion(): void
    {
        $organization = $this->clientOrganization('ORDER-03');
        $user = $this->prepareUser($organization);

        $this->actingAsApiInOrganization(
            $user,
            $organization
        );

        [, $version] = $this->createChecklistAndVersion();

        $section = $this->createSection(
            $version['id'],
            'Equipo de protección personal',
            1
        );

        $a = $this->createQuestion(
            $section['id'],
            'Pregunta A',
            1
        );

        $b = $this->createQuestion(
            $section['id'],
            'Pregunta B',
            5
        );

        $c = $this->createQuestion(
            $section['id'],
            'Pregunta C',
            8
        );

        $this->patchJson(
            "/api/sections/{$section['id']}/questions/reorder",
            [
                'question_ids' => [
                    $c['id'],
                    $a['id'],
                    $b['id'],
                ],
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.0.id', $c['id'])
            ->assertJsonPath('data.0.display_order', 1)
            ->assertJsonPath('data.1.id', $a['id'])
            ->assertJsonPath('data.1.display_order', 2)
            ->assertJsonPath('data.2.id', $b['id'])
            ->assertJsonPath('data.2.display_order', 3);

        $this->assertDatabaseHas('questions', [
            'id' => $c['id'],
            'display_order' => 1,
        ]);

        $this->assertDatabaseHas('questions', [
            'id' => $a['id'],
            'display_order' => 2,
        ]);

        $this->assertDatabaseHas('questions', [
            'id' => $b['id'],
            'display_order' => 3,
        ]);
    }

    public function test_publicar_normaliza_secciones_y_preguntas(): void
    {
        $organization = $this->clientOrganization('ORDER-04');
        $user = $this->prepareUser($organization);

        $this->actingAsApiInOrganization(
            $user,
            $organization
        );

        [$checklist, $version] =
            $this->createChecklistAndVersion();

        $a = $this->createSection(
            $version['id'],
            'Sección A',
            2
        );

        $b = $this->createSection(
            $version['id'],
            'Sección B',
            7
        );

        $q1 = $this->createQuestion(
            $a['id'],
            'Pregunta 1',
            3
        );

        $q2 = $this->createQuestion(
            $a['id'],
            'Pregunta 2',
            9
        );

        $q3 = $this->createQuestion(
            $b['id'],
            'Pregunta 3',
            5
        );

        $this->patchJson(
            "/api/checklists/{$checklist['id']}/versions/{$version['id']}",
            [
                'status' => 'published',
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('checklist_sections', [
            'id' => $a['id'],
            'display_order' => 1,
        ]);

        $this->assertDatabaseHas('checklist_sections', [
            'id' => $b['id'],
            'display_order' => 2,
        ]);

        $this->assertDatabaseHas('questions', [
            'id' => $q1['id'],
            'display_order' => 1,
        ]);

        $this->assertDatabaseHas('questions', [
            'id' => $q2['id'],
            'display_order' => 2,
        ]);

        $this->assertDatabaseHas('questions', [
            'id' => $q3['id'],
            'display_order' => 1,
        ]);

        // Una versión publicada no puede reordenarse.
        $this->patchJson(
            "/api/versions/{$version['id']}/sections/reorder",
            [
                'section_ids' => [
                    $b['id'],
                    $a['id'],
                ],
            ]
        )->assertStatus(422);

        $this->patchJson(
            "/api/sections/{$a['id']}/questions/reorder",
            [
                'question_ids' => [
                    $q2['id'],
                    $q1['id'],
                ],
            ]
        )->assertStatus(422);
    }

    public function test_no_permite_reordenar_sin_permisos(): void
    {
        $organization = $this->clientOrganization('ORDER-05');

        $manager = $this->prepareUser($organization);

        $this->actingAsApiInOrganization(
            $manager,
            $organization
        );

        [, $version] = $this->createChecklistAndVersion();

        $section = $this->createSection(
            $version['id'],
            'Sección A',
            1
        );

        $question = $this->createQuestion(
            $section['id'],
            'Pregunta A',
            1
        );

        $user = $this->user($organization);

        $this->actingAsApiInOrganization(
            $user,
            $organization
        );

        $this->patchJson(
            "/api/versions/{$version['id']}/sections/reorder",
            [
                'section_ids' => [$section['id']],
            ]
        )->assertStatus(403);

        $this->patchJson(
            "/api/sections/{$section['id']}/questions/reorder",
            [
                'question_ids' => [$question['id']],
            ]
        )->assertStatus(403);
    }

    public function test_no_permite_reordenar_otra_organizacion(): void
    {
        $organizationA = $this->clientOrganization('ORDER-06-A');
        $organizationB = $this->clientOrganization('ORDER-06-B');

        $userA = $this->prepareUser($organizationA);
        $userB = $this->prepareUser($organizationB);

        $this->actingAsApiInOrganization(
            $userA,
            $organizationA
        );

        [, $version] = $this->createChecklistAndVersion();

        $section = $this->createSection(
            $version['id'],
            'Sección privada',
            5
        );

        $question = $this->createQuestion(
            $section['id'],
            'Pregunta privada',
            5
        );

        $this->actingAsApiInOrganization(
            $userB,
            $organizationB
        );

        $this->patchJson(
            "/api/versions/{$version['id']}/sections/reorder",
            [
                'section_ids' => [$section['id']],
            ]
        )->assertStatus(404);

        $this->patchJson(
            "/api/sections/{$section['id']}/questions/reorder",
            [
                'question_ids' => [$question['id']],
            ]
        )->assertStatus(404);

        $this->assertDatabaseHas('checklist_sections', [
            'id' => $section['id'],
            'display_order' => 5,
        ]);

        $this->assertDatabaseHas('questions', [
            'id' => $question['id'],
            'display_order' => 5,
        ]);
    }
}
