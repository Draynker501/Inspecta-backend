<?php

namespace Tests\Feature\Checklists;

use App\Authorization\Permission;
use App\Models\RolePermission;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\ColleagueChecklistFixtures;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class QuestionStatusTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;
    use ColleagueChecklistFixtures;

    private function prepareQuestion(): array
    {
        $org = $this->organization('STATUS-A');
        $user = $this->user($org);

        $this->loginForChecklistTests($org, $user);

        $checklistId = $this->createChecklist();
        $versionId = $this->createVersion($checklistId);
        $sectionId = $this->createSection($versionId);
        $questionId = $this->createQuestion($sectionId);

        return [$org, $user, $versionId, $questionId];
    }

    private function url(string $id): string
    {
        return "/api/questions/{$id}";
    }

    public function test_puede_desactivar_y_activar_pregunta(): void
    {
        [, , , $id] = $this->prepareQuestion();

        $this->patchJson($this->url($id), [
            'status' => false,
        ])->assertOk()
          ->assertJsonPath('data.status', false);

        $this->assertDatabaseHas('questions', [
            'id' => $id,
            'status' => false,
        ]);

        $this->patchJson($this->url($id), [
            'status' => true,
        ])->assertOk()
          ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('questions', [
            'id' => $id,
            'status' => true,
        ]);

        $this->assertSame(
            2,
            DB::table('audit_logs')
                ->where('action', 'question.status_changed')
                ->where('auditable_id', $id)
                ->count()
        );
    }

    public function test_estado_repetido_no_genera_auditoria(): void
    {
        [, , , $id] = $this->prepareQuestion();

        $this->patchJson($this->url($id), [
            'status' => false,
        ])->assertOk();

        $this->patchJson($this->url($id), [
            'status' => false,
        ])->assertOk();

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('action', 'question.status_changed')
                ->where('auditable_id', $id)
                ->count()
        );
    }

    public function test_rechaza_estados_invalidos(): void
    {
        [, , , $id] = $this->prepareQuestion();

        foreach ([
            [],
            ['status' => 'invalid'],
            ['status' => null],
            ['status' => 5],
        ] as $payload) {
            $this->patchJson(
                $this->url($id),
                $payload
            )->assertUnprocessable();
        }

        $this->assertDatabaseHas('questions', [
            'id' => $id,
            'status' => true,
        ]);
    }

    public function test_rechaza_campos_adicionales(): void
    {
        [, , , $id] = $this->prepareQuestion();

        $this->patchJson($this->url($id), [
            'status' => false,
            'question_text' => 'Modificación no permitida',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('questions', [
            'id' => $id,
            'status' => true,
        ]);
    }

    public function test_no_modifica_version_publicada(): void
    {
        [, , $versionId, $id] = $this->prepareQuestion();

        DB::table('checklist_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'published_at' => now(),
            ]);

        $this->patchJson($this->url($id), [
            'status' => false,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('questions', [
            'id' => $id,
            'status' => true,
        ]);
    }

    public function test_no_modifica_version_archivada(): void
    {
        [, , $versionId, $id] = $this->prepareQuestion();

        DB::table('checklist_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'archived',
                'published_at' => now(),
            ]);

        $this->patchJson($this->url($id), [
            'status' => false,
        ])->assertUnprocessable();
    }

    public function test_no_modifica_borrador_publicado_anteriormente(): void
    {
        [, , $versionId, $id] = $this->prepareQuestion();

        DB::table('checklist_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'draft',
                'published_at' => now(),
            ]);

        $this->patchJson($this->url($id), [
            'status' => false,
        ])->assertUnprocessable();
    }

    public function test_usuario_sin_permiso_no_puede_modificar(): void
    {
        [$org, , , $id] = $this->prepareQuestion();

        $otherUser = $this->user($org);

        $this->actingAsApiInOrganization($otherUser, $org);

        $this->patchJson($this->url($id), [
            'status' => false,
        ])->assertForbidden();

        $this->assertDatabaseHas('questions', [
            'id' => $id,
            'status' => true,
        ]);
    }

    public function test_usuario_con_solo_lectura_no_puede_modificar(): void
    {
        [$org, , , $id] = $this->prepareQuestion();

        $reader = $this->user($org);
        $role = $this->role($org, 'status-reader');

        app(TenantContext::class)->runAs(
            $org->id,
            fn () => $reader->assignRole($role)
        );

        RolePermission::create([
            'id' => (string) Str::uuid7(),
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permission' => Permission::ChecklistsView->value,
        ]);

        $this->actingAsApiInOrganization($reader, $org);

        $this->patchJson($this->url($id), [
            'status' => false,
        ])->assertForbidden();
    }

    public function test_no_modifica_pregunta_de_otra_organizacion(): void
    {
        [, , , $id] = $this->prepareQuestion();

        $orgB = $this->organization('STATUS-B');
        $userB = $this->user($orgB);

        $this->loginForChecklistTests($orgB, $userB);

        $this->patchJson($this->url($id), [
            'status' => false,
        ])->assertNotFound();

        $this->assertDatabaseHas('questions', [
            'id' => $id,
            'status' => true,
        ]);
    }

    public function test_sin_autenticacion_rechaza_solicitud(): void
    {
        $this->patchJson(
            '/api/questions/'.Str::uuid7(),
            ['status' => false]
        )->assertUnauthorized();
    }

    public function test_fallo_de_auditoria_revierte_cambio(): void
    {
        [, , , $id] = $this->prepareQuestion();

        $this->mock(AuditLogger::class)
            ->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('Audit failed'));

        $this->patchJson($this->url($id), [
            'status' => false,
        ])->assertStatus(500);

        $this->assertDatabaseHas('questions', [
            'id' => $id,
            'status' => true,
        ]);
    }
}