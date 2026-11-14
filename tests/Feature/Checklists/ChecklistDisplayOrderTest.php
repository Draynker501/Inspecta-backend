<?php

namespace Tests\Feature\Checklists;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ChecklistFixtures;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ChecklistDisplayOrderTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;
    use ChecklistFixtures;

    private string $versionId;
    private string $sectionId;

    protected function setUp(): void
    {
        parent::setUp();

        $org = $this->organization('ORG-ORDER');
        $user = $this->user($org);

        $this->loginForChecklistTests($org, $user);

        $checklist = $this->createChecklist();
        $this->versionId = $this->createVersion($checklist);
        $this->sectionId = $this->createSection($this->versionId);
    }

    public function test_primera_seccion_tiene_orden_uno(): void
    {
        $this->assertDatabaseHas('checklist_sections', [
            'id' => $this->sectionId,
            'display_order' => 1,
        ]);
    }

    public function test_siguiente_seccion_incrementa_orden(): void
    {
        $second = $this->createSection(
            $this->versionId,
            'Segunda sección'
        );

        $this->assertDatabaseHas('checklist_sections', [
            'id' => $second,
            'display_order' => 2,
        ]);
    }

    public function test_no_permite_secciones_con_orden_duplicado(): void
    {
        $this->postJson(
            "/api/versions/{$this->versionId}/sections",
            [
                'name' => 'Sección duplicada',
                'display_order' => 1,
            ]
        )->assertStatus(422)
         ->assertJsonValidationErrors(['display_order']);
    }

    public function test_no_permite_actualizar_seccion_a_orden_ocupado(): void
    {
        $second = $this->createSection(
            $this->versionId,
            'Segunda sección'
        );

        $this->putJson("/api/sections/{$second}", [
            'display_order' => 1,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['display_order']);
    }

    public function test_primera_pregunta_tiene_orden_uno(): void
    {
        $question = $this->createQuestion($this->sectionId);

        $this->assertDatabaseHas('questions', [
            'id' => $question,
            'display_order' => 1,
        ]);
    }

    public function test_siguiente_pregunta_incrementa_orden(): void
    {
        $this->createQuestion($this->sectionId, 'Pregunta 1');

        $second = $this->createQuestion(
            $this->sectionId,
            'Pregunta 2'
        );

        $this->assertDatabaseHas('questions', [
            'id' => $second,
            'display_order' => 2,
        ]);
    }

    public function test_no_permite_preguntas_con_orden_duplicado(): void
    {
        $this->createQuestion($this->sectionId);

        $this->postJson(
            "/api/sections/{$this->sectionId}/questions",
            [
                'question_text' => 'Pregunta duplicada',
                'answer_type' => 'boolean',
                'display_order' => 1,
            ]
        )->assertStatus(422)
         ->assertJsonValidationErrors(['display_order']);
    }

    public function test_no_permite_actualizar_pregunta_a_orden_ocupado(): void
    {
        $this->createQuestion($this->sectionId, 'Pregunta 1');

        $second = $this->createQuestion(
            $this->sectionId,
            'Pregunta 2'
        );

        $this->putJson("/api/questions/{$second}", [
            'display_order' => 1,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['display_order']);
    }

    public function test_permite_mismo_orden_en_secciones_diferentes(): void
    {
        $otherSection = $this->createSection(
            $this->versionId,
            'Otra sección'
        );

        $q1 = $this->createQuestion($this->sectionId);
        $q2 = $this->createQuestion($otherSection);

        $this->assertDatabaseHas('questions', [
            'id' => $q1,
            'display_order' => 1,
        ]);

        $this->assertDatabaseHas('questions', [
            'id' => $q2,
            'display_order' => 1,
        ]);
    }

    public function test_orden_automatico_utiliza_maximo_mas_uno(): void
    {
        $this->createQuestion(
            $this->sectionId,
            'Pregunta con orden 4',
            4
        );

        $next = $this->createQuestion(
            $this->sectionId,
            'Pregunta automática'
        );

        $this->assertDatabaseHas('questions', [
            'id' => $next,
            'display_order' => 5,
        ]);
    }

    public function test_no_permite_orden_cero(): void
    {
        $this->postJson(
            "/api/versions/{$this->versionId}/sections",
            [
                'name' => 'Sección inválida',
                'display_order' => 0,
            ]
        )->assertStatus(422)
         ->assertJsonValidationErrors(['display_order']);

        $this->postJson(
            "/api/sections/{$this->sectionId}/questions",
            [
                'question_text' => 'Pregunta inválida',
                'answer_type' => 'boolean',
                'display_order' => 0,
            ]
        )->assertStatus(422)
         ->assertJsonValidationErrors(['display_order']);
    }
}