<?php

namespace Tests\Feature\Checklists;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ChecklistFixtures;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ChecklistPublicationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;
    use ChecklistFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $org = $this->organization('ORG-PUB');
        $user = $this->user($org);

        $this->loginForChecklistTests($org, $user);
    }

    public function test_version_nueva_comienza_en_draft(): void
    {
        $checklist = $this->createChecklist();
        $version = $this->createVersion($checklist);

        $this->assertDatabaseHas('checklist_versions', [
            'id' => $version,
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    public function test_no_puede_publicar_sin_secciones(): void
    {
        $checklist = $this->createChecklist();
        $version = $this->createVersion($checklist);

        $this->patchJson(
            "/api/checklists/{$checklist}/versions/{$version}/status",
            ['status' => 'published']
        )->assertStatus(422)
         ->assertJsonValidationErrors(['sections']);

        $this->assertDatabaseHas('checklist_versions', [
            'id' => $version,
            'status' => 'draft',
        ]);
    }

    public function test_no_puede_publicar_seccion_vacia(): void
    {
        $checklist = $this->createChecklist();
        $version = $this->createVersion($checklist);

        $this->createSection($version);

        $this->patchJson(
            "/api/checklists/{$checklist}/versions/{$version}/status",
            ['status' => 'published']
        )->assertStatus(422)
         ->assertJsonValidationErrors(['sections']);
    }

    public function test_no_puede_publicar_solo_preguntas_inactivas(): void
    {
        $checklist = $this->createChecklist();
        $version = $this->createVersion($checklist);
        $section = $this->createSection($version);
        $question = $this->createQuestion($section);

        $this->patchJson("/api/questions/{$question}/status")
            ->assertOk()
            ->assertJsonPath('data.status', false);

        $this->patchJson(
            "/api/checklists/{$checklist}/versions/{$version}/status",
            ['status' => 'published']
        )->assertStatus(422)
         ->assertJsonValidationErrors(['sections']);
    }

    public function test_no_puede_publicar_checklist_desactivado(): void
    {
        $checklist = $this->createChecklist();
        $version = $this->createVersion($checklist);
        $section = $this->createSection($version);

        $this->createQuestion($section);

        $this->patchJson("/api/checklists/{$checklist}/status", [
            'status' => false,
        ])->assertOk();

        $this->patchJson(
            "/api/checklists/{$checklist}/versions/{$version}/status",
            ['status' => 'published']
        )->assertStatus(422)
         ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('checklist_versions', [
            'id' => $version,
            'status' => 'draft',
        ]);
    }

    public function test_puede_publicar_version_valida(): void
    {
        $checklist = $this->createChecklist();
        $version = $this->createVersion($checklist);
        $section = $this->createSection($version);

        $this->createQuestion($section);

        $this->publishVersion($checklist, $version);

        $this->assertDatabaseHas('checklist_versions', [
            'id' => $version,
            'status' => 'published',
        ]);

        $this->getJson("/api/checklists/{$checklist}/active-version")
            ->assertOk()
            ->assertJsonPath('data.active_version.id', $version);
    }

    public function test_no_puede_editar_version_publicada(): void
    {
        $checklist = $this->createChecklist();
        $version = $this->createVersion($checklist);
        $section = $this->createSection($version);

        $this->createQuestion($section);
        $this->publishVersion($checklist, $version);

        $this->putJson(
            "/api/checklists/{$checklist}/versions/{$version}",
            ['version' => '2.0']
        )->assertStatus(422)
         ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('checklist_versions', [
            'id' => $version,
            'version' => '1.0',
            'status' => 'published',
        ]);
    }

    public function test_version_archivada_no_puede_publicarse(): void
    {
        $checklist = $this->createChecklist();
        $version = $this->createVersion($checklist);

        $url = "/api/checklists/{$checklist}/versions/{$version}/status";

        $this->patchJson($url, [
            'status' => 'archived',
        ])->assertOk();

        $this->patchJson($url, [
            'status' => 'published',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('checklist_versions', [
            'id' => $version,
            'status' => 'archived',
        ]);
    }

    public function test_publicar_nueva_version_archiva_anterior(): void
    {
        $checklist = $this->createChecklist();

        $v1 = $this->createVersion($checklist, '1.0');
        $section1 = $this->createSection($v1);
        $this->createQuestion($section1);

        $this->publishVersion($checklist, $v1);

        $v2 = $this->createVersion($checklist, '2.0');
        $section2 = $this->createSection($v2);
        $this->createQuestion($section2);

        $this->publishVersion($checklist, $v2);

        $this->assertDatabaseHas('checklist_versions', [
            'id' => $v1,
            'status' => 'archived',
        ]);

        $this->assertDatabaseHas('checklist_versions', [
            'id' => $v2,
            'status' => 'published',
        ]);
    }
}