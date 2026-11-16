<?php

namespace Tests\Feature\Checklists;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\Question;
use App\Models\QuestionNormRequirement;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class QuestionDraftWriteTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_ediciones_de_borrador_validan_campos_opciones_estado_y_auditoria(): void
    {
        $this->registerUpdateRoutes();
        [$org, $user, $version, $section] = $this->setupDraft();
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Q', 'answer_type' => 'text', 'display_order' => 1]);
        ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'Otra', 'display_order' => 2]);
        Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Q2', 'answer_type' => 'text', 'display_order' => 2]);
        $this->actingAsApiInOrganization($user, $org);
        $sectionUrl = "/api/sections/{$section->id}";
        $questionUrl = "/api/questions/{$question->id}";
        $this->putJson($sectionUrl, ['organization_id' => $org->id])->assertUnprocessable();
        $this->putJson($questionUrl, ['status' => false])->assertUnprocessable();
        $this->putJson($sectionUrl, ['display_order' => 2])->assertUnprocessable();
        $this->putJson($questionUrl, ['display_order' => 2])->assertUnprocessable();
        $this->putJson($questionUrl, ['answer_type' => 'multiple_choice'])->assertUnprocessable();
        $options = [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']];
        $this->putJson($questionUrl, ['answer_type' => 'multiple_choice', 'options' => [$options[0], $options[0]]])->assertUnprocessable();
        $this->putJson($questionUrl, ['options' => $options])->assertUnprocessable();
        $this->putJson($sectionUrl, ['name' => 'Editada'])->assertOk()->assertJsonPath('data.name', 'Editada');
        $this->putJson($questionUrl, ['answer_type' => 'multiple_choice', 'options' => $options])->assertOk()->assertJsonPath('data.options', $options);
        $this->putJson($questionUrl, ['question_text' => 'Editada'])->assertOk()->assertJsonPath('data.options', $options);
        $this->putJson($questionUrl, ['answer_type' => 'boolean'])->assertOk()->assertJsonPath('data.options', null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'checklist_section.updated', 'organization_id' => $org->id, 'user_id' => $user->id, 'auditable_id' => $section->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'question.updated', 'organization_id' => $org->id, 'user_id' => $user->id, 'auditable_id' => $question->id]);
        $version->update(['status' => 'draft', 'published_at' => now()]);
        $this->putJson($sectionUrl, ['name' => 'No'])->assertUnprocessable();
        $this->putJson($questionUrl, ['question_text' => 'No'])->assertUnprocessable();
        foreach (['published', 'archived'] as $status) {
            $version->update(['status' => $status]);
            $this->putJson($sectionUrl, ['name' => 'No'])->assertUnprocessable();
            $this->putJson($questionUrl, ['question_text' => 'No'])->assertUnprocessable();
        }
        $this->assertSame('Editada', $section->fresh()->name);
        $this->assertSame('Editada', $question->fresh()->question_text);
    }

    public function test_ediciones_revienten_si_falla_auditoria_y_aislan_tenant(): void
    {
        $this->registerUpdateRoutes();
        [$org, $user, , $section] = $this->setupDraft();
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Q', 'answer_type' => 'text', 'display_order' => 1]);
        $other = $this->organization('EDIT-OTHER');
        $otherUser = $this->user($other);
        $role = $this->role($other, 'edit-other');
        app(TenantContext::class)->runAs($other->id, fn () => $otherUser->assignRole($role));
        RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $other->id, 'permission' => Permission::ChecklistsManage->value]);
        $this->actingAsApiInOrganization($otherUser, $other);
        $this->putJson("/api/sections/{$section->id}", ['name' => 'No'])->assertNotFound();
        $this->putJson("/api/questions/{$question->id}", ['question_text' => 'No'])->assertNotFound();
        $this->actingAsApiInOrganization($user, $org);
        $this->mock(AuditLogger::class)->shouldReceive('record')->twice()->andThrow(new RuntimeException('audit failed'));
        $this->putJson("/api/sections/{$section->id}", ['name' => 'No'])->assertStatus(500);
        $this->putJson("/api/questions/{$question->id}", ['question_text' => 'No'])->assertStatus(500);
        $this->assertSame('S', $section->fresh()->name);
        $this->assertSame('Q', $question->fresh()->question_text);
    }

    private function registerUpdateRoutes(): void {}

    public function test_escrituras_bloquean_organizacion_antes_de_jerarquia(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('La instrumentación de locks utiliza la gramática SQLite.');
        }

        $this->registerUpdateRoutes();
        [$org, $user, $version, $section] = $this->setupDraft();
        $this->actingAsApiInOrganization($user, $org);
        $connection = DB::connection();
        $originalGrammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                return $value === true ? '/* write-lock */' : '';
            }
        });
        $locks = [];
        DB::listen(function ($event) use (&$locks, $connection): void {
            if (str_contains($event->sql, '/* write-lock */')) {
                $locks[] = [$event->sql, $event->bindings, $connection->transactionLevel()];
            }
        });

        try {
            $this->postJson("/api/versions/{$version->id}/sections", ['name' => 'Otra'])->assertCreated();
            $this->assertCount(3, $locks);
            foreach (['organizations', 'checklists', 'checklist_versions'] as $index => $table) {
                $this->assertStringContainsString('from "'.$table.'"', $locks[$index][0]);
                $this->assertGreaterThan(0, $locks[$index][2]);
            }
            $this->assertContains($org->id, $locks[0][1]);

            $locks = [];
            $response = $this->postJson("/api/sections/{$section->id}/questions", ['question_text' => 'Q', 'answer_type' => 'text'])->assertCreated();
            $this->assertCount(4, $locks);
            foreach (['organizations', 'checklists', 'checklist_versions', 'checklist_sections'] as $index => $table) {
                $this->assertStringContainsString('from "'.$table.'"', $locks[$index][0]);
                $this->assertGreaterThan(0, $locks[$index][2]);
            }
            $this->assertContains($org->id, $locks[0][1]);

            $locks = [];
            $this->putJson("/api/sections/{$section->id}", ['name' => 'Editada'])->assertOk();
            $this->assertCount(4, $locks);
            foreach (['organizations', 'checklists', 'checklist_versions', 'checklist_sections'] as $index => $table) {
                $this->assertStringContainsString('from "'.$table.'"', $locks[$index][0]);
                $this->assertGreaterThan(0, $locks[$index][2]);
            }
            $this->assertContains($org->id, $locks[0][1]);

            $locks = [];
            $this->putJson('/api/questions/'.$response->json('data.id'), ['question_text' => 'Editada'])->assertOk();
            $this->assertCount(5, $locks);
            foreach (['organizations', 'checklists', 'checklist_versions', 'checklist_sections', 'questions'] as $index => $table) {
                $this->assertStringContainsString('from "'.$table.'"', $locks[$index][0]);
                $this->assertGreaterThan(0, $locks[$index][2]);
            }
            $this->assertContains($org->id, $locks[0][1]);
        } finally {
            $connection->setQueryGrammar($originalGrammar);
        }
    }

    public function test_preguntas_de_borrador_validan_opciones_orden_y_auditoria(): void
    {
        [$org, $user, $version, $section] = $this->setupDraft();
        $this->actingAsApiInOrganization($user, $org);
        $url = "/api/sections/{$section->id}/questions";
        $this->postJson($url, ['question_text' => 'Q', 'answer_type' => 'yes_no'])->assertUnprocessable();
        $this->postJson($url, ['question_text' => 'Q', 'answer_type' => 'multiple_choice'])->assertUnprocessable();
        $this->postJson($url, ['question_text' => 'Q', 'answer_type' => 'boolean', 'options' => [['value' => 'a', 'label' => 'A']]])->assertUnprocessable();
        $options = [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']];
        $response = $this->postJson($url, ['question_text' => 'Q', 'answer_type' => 'multiple_choice', 'options' => $options])->assertCreated()
            ->assertJsonPath('data.display_order', 1)->assertJsonPath('data.options', $options);
        $this->postJson($url, ['question_text' => 'Q2', 'answer_type' => 'boolean', 'display_order' => 1])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'question.created', 'organization_id' => $org->id, 'user_id' => $user->id, 'auditable_id' => $response->json('data.id')]);
        $version->update(['status' => 'archived', 'published_at' => now()]);
        $this->postJson($url, ['question_text' => 'Q2', 'answer_type' => 'boolean'])->assertUnprocessable();
        $this->assertSame(1, DB::table('questions')->count());
    }

    public function test_fallo_de_auditoria_revierte_pregunta(): void
    {
        [$org, $user, , $section] = $this->setupDraft();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failed'));
        $this->actingAsApiInOrganization($user, $org)->postJson("/api/sections/{$section->id}/questions", ['question_text' => 'Q', 'answer_type' => 'text'])->assertStatus(500);
        $this->assertSame(0, DB::table('questions')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'question.created')->count());
    }

    public function test_opciones_rechazan_valores_duplicados_estructura_y_limites_invalidos(): void
    {
        [$org, $user, , $section] = $this->setupDraft();
        $this->actingAsApiInOrganization($user, $org);
        $url = "/api/sections/{$section->id}/questions";
        $base = ['question_text' => 'Q', 'answer_type' => 'multiple_choice'];
        $invalid = [
            [['value' => 'a', 'label' => 'A'], ['value' => 'a', 'label' => 'B']],
            [['value' => 'a', 'label' => 'A', 'extra' => true], ['value' => 'b', 'label' => 'B']],
            [1 => ['value' => 'a', 'label' => 'A'], 2 => ['value' => 'b', 'label' => 'B']],
            [['value' => str_repeat('a', 101), 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
            [['value' => 'a', 'label' => str_repeat('A', 151)], ['value' => 'b', 'label' => 'B']],
            [['value' => 'a', 'label' => 'A']],
        ];

        foreach ($invalid as $options) {
            $this->postJson($url, $base + ['options' => $options])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('questions')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'question.created')->count());

        // El contrato exige unicidad del valor, no de la etiqueta visible.
        $options = [
            ['value' => str_repeat('a', 100), 'label' => str_repeat('A', 150)],
            ['value' => 'b', 'label' => str_repeat('A', 150)],
        ];
        $this->postJson($url, $base + ['options' => $options])->assertCreated()->assertJsonPath('data.options', $options);
        $this->assertSame(1, DB::table('questions')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'question.created')->count());
    }

    public function test_autorizacion_antes_de_resolver_y_aislamiento(): void
    {
        [$org, $user, , $section] = $this->setupDraft();
        $other = $this->organization('QUESTION-OTHER');
        $otherUser = $this->user($other);
        $this->postJson("/api/sections/{$section->id}/questions", [])->assertUnauthorized();
        $this->actingAsApiInOrganization($otherUser, $other)
            ->postJson("/api/sections/{$section->id}/questions", [])->assertForbidden();
        $role = $this->role($other, 'foreign-writer');
        app(TenantContext::class)->runAs($other->id, fn () => $otherUser->assignRole($role));
        RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $other->id, 'permission' => Permission::ChecklistsManage->value]);
        $this->postJson("/api/sections/{$section->id}/questions", ['question_text' => 'Q', 'answer_type' => 'text'])->assertNotFound();
        $this->assertSame(0, DB::table('questions')->count());
    }

    public function test_modelo_pivote_usa_tabla_singular_y_expone_identificador(): void
    {
        [$org, , , $section] = $this->setupDraft();
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Q', 'answer_type' => 'boolean', 'display_order' => 1]);
        $norm = Norm::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'code' => 'N', 'name' => 'Norma', 'status' => true]);
        $requirement = NormRequirement::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'norm_id' => $norm->id, 'code' => 'R', 'description' => 'Requisito']);
        $link = QuestionNormRequirement::create(['organization_id' => $org->id, 'question_id' => $question->id, 'norm_requirement_id' => $requirement->id]);

        $this->assertSame('question_norm_requirement', $link->getTable());
        $this->assertSame($link->id, $question->normRequirements()->firstOrFail()->pivot->id);
    }

    public function test_conflicto_unico_de_pregunta_es_422_y_no_audita(): void
    {
        [$org, $user, , $section] = $this->setupDraft();
        Question::creating(function (): void {
            $exception = new PDOException('unique');
            $exception->errorInfo = ['23000', 19, 'UNIQUE constraint failed: questions.checklist_section_id, questions.display_order'];
            throw new QueryException('sqlite', 'insert', [], $exception);
        });
        $this->actingAsApiInOrganization($user, $org)->postJson("/api/sections/{$section->id}/questions", ['question_text' => 'Q', 'answer_type' => 'text'])->assertUnprocessable();
        $this->assertSame(0, DB::table('questions')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'question.created')->count());
    }

    private function setupDraft(): array
    {
        $org = $this->organization('QUESTION-WRITE');
        $user = $this->user($org);
        $role = $this->role($org, 'question-writer');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $org->id, 'permission' => Permission::ChecklistsManage->value]);
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'C', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'version' => '1', 'status' => 'draft', 'created_by' => $user->id]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1]);

        return [$org, $user, $version, $section];
    }
}
