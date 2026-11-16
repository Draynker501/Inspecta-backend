<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Models\AuditLog;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Inspection;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\Organization;
use App\Models\Question;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class InspectionAnswerHttpTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $org = $this->clientOrganization('INS02');
        app(TenantContext::class)->set($org->id);

        $plant = $this->plant($org, 'P1');
        $area = $this->area($plant, 'A1');
        $sibling = $this->area($plant, 'A2');

        $checklist = Checklist::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'name' => 'Checklist histórico',
            'status' => true,
        ]);
        $version = ChecklistVersion::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'checklist_id' => $checklist->id,
            'created_by' => $this->user($org)->id,
            'version' => '1',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $sectionA = ChecklistSection::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'checklist_version_id' => $version->id,
            'name' => 'Sección A',
            'display_order' => 1,
        ]);
        $sectionB = ChecklistSection::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'checklist_version_id' => $version->id,
            'name' => 'Sección B',
            'display_order' => 2,
        ]);

        $options = [
            ['value' => 'si', 'label' => 'Sí'],
            ['value' => 'no', 'label' => 'No'],
        ];
        $questions = [];
        foreach ([
            'boolean' => ['boolean', 1, $sectionA],
            'text' => ['text', 2, $sectionA],
            'number' => ['number', 3, $sectionA],
            'date' => ['date', 4, $sectionA],
            'choice' => ['multiple_choice', 5, $sectionA],
            'signature' => ['signature', 6, $sectionA],
            'inactive' => ['text', 7, $sectionA],
            'ordered' => ['text', 1, $sectionB],
        ] as $key => [$answerType, $order, $section]) {
            $questions[$key] = Question::create([
                'id' => (string) Str::uuid7(),
                'organization_id' => $org->id,
                'checklist_section_id' => $section->id,
                'question_text' => "Pregunta {$key}",
                'description' => "Descripción {$key}",
                'answer_type' => $answerType,
                'options' => $answerType === 'multiple_choice' ? $options : null,
                'display_order' => $order,
                'required' => $key !== 'ordered',
                'allows_evidence' => $key === 'boolean',
                'status' => $key !== 'inactive',
            ]);
        }

        $norm = Norm::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'code' => 'NORMA-INS02',
            'name' => 'Norma INS-02',
            'status' => true,
        ]);
        $requirement = NormRequirement::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'norm_id' => $norm->id,
            'code' => 'REQ-1',
            'section' => '4.1',
            'description' => 'Requisito vinculado',
        ]);
        DB::table('question_norm_requirement')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'question_id' => $questions['boolean']->id,
            'norm_requirement_id' => $requirement->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owner = $this->userWith($org, [Permission::InspectionsExecute], $area);
        $manager = $this->userWith($org, [Permission::InspectionsManage], $area);
        $executor = $this->userWith($org, [Permission::InspectionsExecute], $area);
        $ownerManager = $this->userWith($org, [Permission::InspectionsManage], $area);
        $revoked = $this->userWith($org, [Permission::InspectionsExecute], $area);
        $siblingUser = $this->userWith($org, [Permission::InspectionsExecute], $sibling);
        $noAssignment = $this->userWith($org, [Permission::InspectionsManage], null);
        $viewAllNoAssignment = $this->userWith(
            $org,
            [Permission::InspectionsExecute, Permission::LocationsViewAll],
            null,
        );

        $inspection = $this->inspection($owner, $version, $plant, $area);
        $managedInspection = $this->inspection($ownerManager, $version, $plant, $area);

        return compact(
            'org', 'plant', 'area', 'sibling', 'checklist', 'version', 'sectionA', 'sectionB',
            'questions', 'norm', 'requirement', 'owner', 'manager', 'executor', 'ownerManager',
            'revoked', 'siblingUser', 'noAssignment', 'viewAllNoAssignment', 'inspection',
            'managedInspection',
        );
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function userWith(Organization $org, array $permissions, ?object $area): User
    {
        $user = $this->user($org);
        $role = $this->role($org, 'r-'.Str::uuid7());
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        foreach ($permissions as $permission) {
            RolePermission::create([
                'id' => (string) Str::uuid7(),
                'organization_id' => $org->id,
                'role_id' => $role->id,
                'permission' => $permission->value,
            ]);
        }

        if ($area !== null) {
            DB::table('user_areas')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'area_id' => $area->id,
            ]);
        }

        return $user;
    }

    private function inspection(User $user, ChecklistVersion $version, object $plant, object $area, string $status = 'draft'): Inspection
    {
        app(TenantContext::class)->set($version->organization_id);

        return Inspection::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $version->organization_id,
            'user_id' => $user->id,
            'checklist_version_id' => $version->id,
            'plant_id' => $plant->id,
            'area_id' => $area->id,
            'status' => $status,
            'started_at' => now(),
        ]);
    }

    public function test_checklist_historico_no_depende_de_estado_actual_ni_de_permisos_de_catalogo(): void
    {
        $f = $this->fixture();

        // La versión se archiva, el checklist se desactiva y la normativa se
        // desactiva después de crear la inspección. Además se publica otra
        // versión y se desactiva una pregunta.
        DB::table('checklist_versions')->where('id', $f['version']->id)->update(['status' => 'archived']);
        DB::table('checklists')->where('id', $f['checklist']->id)->update(['status' => false]);
        DB::table('norms')->where('id', $f['norm']->id)->update(['status' => false]);
        DB::table('questions')->where('id', $f['questions']['inactive']->id)->update(['status' => false]);

        $newVersion = ChecklistVersion::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'checklist_id' => $f['checklist']->id,
            'created_by' => $f['owner']->id,
            'version' => '2',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $newSection = ChecklistSection::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'checklist_version_id' => $newVersion->id,
            'name' => 'Sección nueva',
            'display_order' => 1,
        ]);
        $newQuestion = Question::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'checklist_section_id' => $newSection->id,
            'question_text' => 'Pregunta nueva',
            'answer_type' => 'text',
        ]);

        $this->assertSame([], DB::table('role_permissions')
            ->whereIn('permission', [Permission::ChecklistsView->value, Permission::NormsView->value])
            ->pluck('permission')->all());

        $response = $this->actingAsApiInOrganization($f['owner'], $f['org'])
            ->getJson('/api/inspections/'.$f['inspection']->id.'/checklist')
            ->assertOk()
            ->assertJsonPath('data.inspection_id', $f['inspection']->id)
            ->assertJsonPath('data.checklist.id', $f['checklist']->id)
            ->assertJsonPath('data.checklist.status', false)
            ->assertJsonPath('data.checklist_version.id', $f['version']->id)
            ->assertJsonPath('data.checklist_version.version', '1')
            ->assertJsonPath('data.checklist_version.status', 'archived')
            ->assertJsonPath('data.sections.0.id', $f['sectionA']->id)
            ->assertJsonPath('data.sections.1.id', $f['sectionB']->id)
            ->assertJsonPath('data.sections.0.questions.0.id', $f['questions']['boolean']->id)
            ->assertJsonPath('data.sections.0.questions.0.supported', true)
            ->assertJsonPath('data.sections.0.questions.4.options', $f['questions']['choice']->options)
            ->assertJsonPath('data.sections.0.questions.5.answer_type', 'signature')
            ->assertJsonPath('data.sections.0.questions.5.supported', false)
            ->assertJsonPath('data.sections.0.questions.0.norm_requirements.0.id', $f['requirement']->id)
            ->assertJsonPath('data.sections.0.questions.0.norm_requirements.0.code', 'REQ-1');

        $this->assertNotContains($f['questions']['inactive']->id, collect($response->json('data.sections.0.questions'))->pluck('id'));
        $this->assertNotContains($newQuestion->id, collect($response->json('data.sections'))->flatMap(fn ($s) => $s['questions'])->pluck('id'));
        $this->assertNotNull($response->json('data.checklist_version.published_at'));
    }

    public function test_checklist_y_respuestas_leen_propia_ajena_y_rechazan_otros_ambitos(): void
    {
        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id;

        // Propietario con execute.
        $this->actingAsApiInOrganization($f['owner'], $f['org'])->getJson($url.'/checklist')->assertOk();
        // Administrador con manage sobre inspección ajena y asignación vigente.
        $this->actingAsApiInOrganization($f['manager'], $f['org'])->getJson($url.'/checklist')->assertOk();
        // Ejecutor ajeno sin manage.
        $this->actingAsApiInOrganization($f['executor'], $f['org'])->getJson($url.'/checklist')->assertNotFound();
        // Área hermana: no sustituye la asignación del área de la inspección.
        $this->actingAsApiInOrganization($f['siblingUser'], $f['org'])->getJson($url.'/checklist')->assertNotFound();
        // manage sin asignación.
        $this->actingAsApiInOrganization($f['noAssignment'], $f['org'])->getJson($url.'/checklist')->assertNotFound();
        // locations.view_all no concede acceso a la inspección.
        $this->actingAsApiInOrganization($f['viewAllNoAssignment'], $f['org'])->getJson($url.'/checklist')->assertNotFound();

        DB::table('organization_memberships')
            ->where('user_id', $f['revoked']->id)
            ->where('organization_id', $f['org']->id)
            ->update(['status' => 'revoked']);
        $this->actingAsApiInOrganization($f['revoked'], $f['org'])->getJson($url.'/checklist')->assertForbidden();

        $otherOrg = $this->clientOrganization('INS02-OTHER');
        $otherUser = $this->user($otherOrg);
        $otherPlant = $this->plant($otherOrg, 'PX');
        $otherArea = $this->area($otherPlant, 'AX');
        $otherChecklist = Checklist::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $otherOrg->id,
            'name' => 'Otra',
            'status' => true,
        ]);
        $otherVersion = ChecklistVersion::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $otherOrg->id,
            'checklist_id' => $otherChecklist->id,
            'created_by' => $otherUser->id,
            'version' => '1',
            'status' => 'published',
        ]);
        $foreign = $this->inspection($otherUser, $otherVersion, $otherPlant, $otherArea);

        $this->actingAsApiInOrganization($f['owner'], $f['org'])
            ->getJson('/api/inspections/'.$foreign->id.'/checklist')
            ->assertNotFound();

        // Misma matriz para el listado de respuestas.
        $this->actingAsApiInOrganization($f['owner'], $f['org'])->getJson($url.'/answers')->assertOk();
        $this->actingAsApiInOrganization($f['manager'], $f['org'])->getJson($url.'/answers')->assertOk();
        $this->actingAsApiInOrganization($f['executor'], $f['org'])->getJson($url.'/answers')->assertNotFound();
        $this->actingAsApiInOrganization($f['siblingUser'], $f['org'])->getJson($url.'/answers')->assertNotFound();
        $this->actingAsApiInOrganization($f['revoked'], $f['org'])->getJson($url.'/answers')->assertForbidden();
        $this->actingAsApiInOrganization($f['owner'], $f['org'])
            ->getJson('/api/inspections/'.$foreign->id.'/answers')->assertNotFound();
    }

    public function test_put_acepta_los_cinco_tipos_y_deriva_version_y_seccion(): void
    {
        $f = $this->fixture();
        $cases = [
            'boolean' => 'true',
            'text' => 'Texto libre',
            'number' => '-0012.50',
            'date' => '2024-02-29',
            'choice' => 'no',
        ];

        foreach ($cases as $key => $value) {
            $response = $this->actingAsApiInOrganization($f['owner'], $f['org'])
                ->putJson(
                    '/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions'][$key]->id,
                    ['answer' => $value],
                )
                ->assertOk()
                ->assertJsonPath('data.inspection_id', $f['inspection']->id)
                ->assertJsonPath('data.question_id', $f['questions'][$key]->id)
                ->assertJsonPath('data.answer', $value)
                ->assertJsonPath('data.observation', null)
                ->assertJsonStructure(['data' => ['id', 'inspection_id', 'question_id', 'answer', 'observation', 'created_at', 'updated_at']])
                ->assertJsonMissingPath('data.checklist_version_id');

            $id = $response->json('data.id');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
            $this->assertDatabaseHas('inspection_answers', [
                'id' => $id,
                'inspection_id' => $f['inspection']->id,
                'question_id' => $f['questions'][$key]->id,
                'organization_id' => $f['org']->id,
                'checklist_version_id' => $f['version']->id,
                'checklist_section_id' => $f['questions'][$key]->checklist_section_id,
            ]);
        }
    }

    public function test_put_rechaza_valores_invalidos_campos_protegidos_y_preguntas_ajenas(): void
    {
        $f = $this->fixture();
        $base = '/api/inspections/'.$f['inspection']->id.'/answers/';
        $client = fn () => $this->actingAsApiInOrganization($f['owner'], $f['org']);

        $invalid = [
            'boolean' => ['TRUE', '1', '__NA__', ''],
            'text' => ['', str_repeat('a', 65536)],
            'number' => ['1e3', '1,5', 'INF', 'NAN', '1.', '+1', str_repeat('1', 65)],
            'date' => ['2024-02-30', '2024-2-1', 'ayer'],
            'choice' => ['otra', 'SI'],
            'signature' => ['firma'],
        ];

        foreach ($invalid as $key => $values) {
            foreach ($values as $value) {
                $client()->putJson($base.$f['questions'][$key]->id, ['answer' => $value])->assertUnprocessable();
            }
        }

        foreach ([
            ['observation' => 'Sin respuesta'],
            ['answer' => null],
            ['answer' => ['true']],
            ['answer' => 'true', 'organization_id' => $f['org']->id],
            ['answer' => 'true', 'inspection_id' => $f['inspection']->id],
            ['answer' => 'true', 'checklist_version_id' => $f['version']->id],
            ['answer' => 'true', 'checklist_section_id' => $f['sectionA']->id],
            ['answer' => 'true', 'id' => (string) Str::uuid7()],
            ['answer' => 'true', 'created_at' => now()->toISOString()],
            ['answer' => 'true', 'updated_at' => now()->toISOString()],
            ['answer' => 'true', 'status' => 'completed'],
        ] as $body) {
            $client()->putJson($base.$f['questions']['boolean']->id, $body)->assertUnprocessable();
        }

        $client()->putJson($base.$f['questions']['inactive']->id, ['answer' => 'Texto'])->assertUnprocessable();

        $otherVersion = ChecklistVersion::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'checklist_id' => $f['checklist']->id,
            'created_by' => $f['owner']->id,
            'version' => '9',
            'status' => 'draft',
        ]);
        $otherSection = ChecklistSection::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'checklist_version_id' => $otherVersion->id,
            'name' => 'Otra',
            'display_order' => 1,
        ]);
        $foreignQuestion = Question::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'checklist_section_id' => $otherSection->id,
            'question_text' => 'Ajena a la versión',
            'answer_type' => 'text',
        ]);
        $client()->putJson($base.$foreignQuestion->id, ['answer' => 'Texto'])->assertNotFound();
        $client()->putJson($base.(string) Str::uuid7(), ['answer' => 'Texto'])->assertNotFound();

        $this->assertSame(0, DB::table('inspection_answers')->count());
    }

    public function test_put_limites_de_bytes_y_observacion(): void
    {
        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions']['text']->id;
        $client = fn () => $this->actingAsApiInOrganization($f['owner'], $f['org']);

        $max = str_repeat('á', 32767).'x'; // 65535 bytes en UTF-8.
        $this->assertSame(65535, strlen($max));
        $client()->putJson($url, ['answer' => $max])->assertOk();

        $client()->putJson($url, ['answer' => $max.'x'])->assertUnprocessable();
        $client()->putJson($url, ['answer' => ' ok ', 'observation' => str_repeat('o', 65536)])->assertUnprocessable();
        $client()->putJson($url, ['answer' => ' ok ', 'observation' => ' conserva espacios '])->assertOk();
        $this->assertSame(' conserva espacios ', DB::table('inspection_answers')->value('observation'));
    }

    public function test_put_rechaza_texto_compuesto_solo_por_espacios_sin_recortar_textos_validos(): void
    {
        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions']['text']->id;
        $client = fn () => $this->actingAsApiInOrganization($f['owner'], $f['org']);

        // Espacios ASCII, tabuladores, saltos de línea y separadores Unicode:
        // un texto sin ningún carácter significativo no es una respuesta.
        foreach ([' ', '   ', "\t", "\n", "\r\n", " \t\n ", "\u{00A0}", "\u{3000}"] as $blank) {
            $client()->putJson($url, ['answer' => $blank])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('answer');
        }

        $this->assertSame(0, DB::table('inspection_answers')->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('action', 'like', 'inspection.answer_%')
            ->count());

        // Un texto válido no se recorta: se conserva byte a byte.
        $client()->putJson($url, ['answer' => '  Texto con espacios  '])
            ->assertOk()
            ->assertJsonPath('data.answer', '  Texto con espacios  ');
        $this->assertSame('  Texto con espacios  ', DB::table('inspection_answers')->value('answer'));

        // Un solo carácter significativo rodeado de espacios ya es válido.
        $client()->putJson($url, ['answer' => " \t x \n "])
            ->assertOk()
            ->assertJsonPath('data.answer', " \t x \n ");
        $this->assertSame(" \t x \n ", DB::table('inspection_answers')->value('answer'));
    }

    public function test_put_es_reemplazo_completo_idempotente_y_auditoria_minimizada(): void
    {
        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions']['text']->id;
        $client = fn () => $this->actingAsApiInOrganization($f['owner'], $f['org']);

        $first = $client()->putJson($url, ['answer' => 'valor inicial', 'observation' => 'observación inicial'])
            ->assertOk();
        $this->travel(2)->seconds();

        // PUT idéntico: sin cambios de timestamp ni auditoría adicional.
        $second = $client()->putJson($url, ['answer' => 'valor inicial', 'observation' => 'observación inicial'])
            ->assertOk();
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, DB::table('inspection_answers')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_created')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.answer_updated')->count());

        // Omitir observation equivale a null; se audita el campo modificado.
        $this->travel(2)->seconds();
        $client()->putJson($url, ['answer' => 'valor nuevo'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'valor nuevo')
            ->assertJsonPath('data.observation', null);

        $updated = DB::table('audit_logs')->where('action', 'inspection.answer_updated')->get();
        $this->assertCount(1, $updated);
        $payload = json_encode([$updated[0]->old_values, $updated[0]->new_values], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('valor nuevo', $payload);
        $this->assertStringNotContainsString('observación inicial', $payload);
        $this->assertStringContainsString('answer_present', $payload);
        $this->assertStringContainsString('observation_present', $payload);
        $this->assertSame(['answer', 'observation'], json_decode($updated[0]->new_values, true)['changed']);

        // Sólo observación.
        $this->travel(2)->seconds();
        $client()->putJson($url, ['answer' => 'valor nuevo', 'observation' => 'nueva observación'])->assertOk();
        $this->assertCount(2, DB::table('audit_logs')->where('action', 'inspection.answer_updated')->get());
        $this->assertSame(
            ['observation'],
            json_decode(DB::table('audit_logs')->where('action', 'inspection.answer_updated')->orderByDesc('id')->value('new_values'), true)['changed'],
        );

        // PUT idéntico final: no crea eventos.
        $client()->putJson($url, ['answer' => 'valor nuevo', 'observation' => 'nueva observación'])->assertOk();
        $this->assertCount(2, DB::table('audit_logs')->where('action', 'inspection.answer_updated')->get());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_created')->count());
    }

    public function test_delete_es_idempotente_bloquea_evidencias_y_respeta_estado_y_autorizacion(): void
    {
        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions']['boolean']->id;
        $client = fn () => $this->actingAsApiInOrganization($f['owner'], $f['org']);

        $answerId = $client()->putJson($url, ['answer' => 'true'])->json('data.id');
        $client()->deleteJson($url)->assertNoContent();
        $this->assertDatabaseMissing('inspection_answers', ['id' => $answerId]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());

        // Delete repetido: 204 y sin auditoría adicional.
        $client()->deleteJson($url)->assertNoContent();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());

        // Pregunta válida pero respuesta ausente tras eliminar.
        $this->assertSame(0, DB::table('inspection_answers')->count());

        // Evidencias: se conserva la respuesta y la evidencia.
        $answerId = $client()->putJson($url, ['answer' => 'false'])->json('data.id');
        DB::table('evidences')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'inspection_id' => $f['inspection']->id,
            'inspection_answer_id' => $answerId,
            'uploaded_by' => $f['owner']->id,
            'file_name' => 'evidencia.jpg',
            'storage_path' => 'ins02/evidencia.jpg',
        ]);
        $client()->deleteJson($url)->assertUnprocessable();
        $this->assertDatabaseHas('inspection_answers', ['id' => $answerId]);
        $this->assertSame(1, DB::table('evidences')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());

        // Autorización de escritura: manage no concede, execute ajeno no ve.
        $this->actingAsApiInOrganization($f['manager'], $f['org'])->deleteJson($url)->assertForbidden();
        $this->actingAsApiInOrganization($f['executor'], $f['org'])->deleteJson($url)->assertNotFound();
        $this->actingAsApiInOrganization($f['ownerManager'], $f['org'])
            ->deleteJson('/api/inspections/'.$f['managedInspection']->id.'/answers/'.$f['questions']['text']->id)
            ->assertForbidden();

        DB::table('organization_memberships')
            ->where('user_id', $f['revoked']->id)
            ->where('organization_id', $f['org']->id)
            ->update(['status' => 'revoked']);
        $this->actingAsApiInOrganization($f['revoked'], $f['org'])->deleteJson($url)->assertForbidden();

        // Pregunta de otra versión o inexistente: 404, incluso sin respuesta.
        $otherVersion = ChecklistVersion::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'checklist_id' => $f['checklist']->id,
            'created_by' => $f['owner']->id,
            'version' => '8',
            'status' => 'draft',
        ]);
        $otherSection = ChecklistSection::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'checklist_version_id' => $otherVersion->id,
            'name' => 'Otra',
            'display_order' => 1,
        ]);
        $foreignQuestion = Question::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'checklist_section_id' => $otherSection->id,
            'question_text' => 'Otra versión',
            'answer_type' => 'text',
        ]);
        $client()->deleteJson('/api/inspections/'.$f['inspection']->id.'/answers/'.$foreignQuestion->id)->assertNotFound();
        $client()->deleteJson('/api/inspections/'.$f['inspection']->id.'/answers/'.Str::uuid7())->assertNotFound();

        // Inspección no draft: ni PUT ni DELETE.
        DB::table('inspections')->where('id', $f['inspection']->id)->update(['status' => 'completed']);
        $client()->putJson($url, ['answer' => 'true'])->assertUnprocessable();
        $client()->deleteJson($url)->assertUnprocessable();
        $this->assertDatabaseHas('inspection_answers', ['id' => $answerId]);
    }

    public function test_put_requiere_propietario_execute_membresia_y_asignacion_sin_bypass(): void
    {
        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions']['text']->id;
        $body = ['answer' => 'Texto'];

        $this->actingAsApiInOrganization($f['manager'], $f['org'])->putJson($url, $body)->assertForbidden();
        $this->actingAsApiInOrganization($f['ownerManager'], $f['org'])
            ->putJson('/api/inspections/'.$f['managedInspection']->id.'/answers/'.$f['questions']['text']->id, $body)
            ->assertForbidden();
        $this->actingAsApiInOrganization($f['executor'], $f['org'])->putJson($url, $body)->assertNotFound();
        $this->actingAsApiInOrganization($f['siblingUser'], $f['org'])->putJson($url, $body)->assertNotFound();
        $this->actingAsApiInOrganization($f['noAssignment'], $f['org'])->putJson($url, $body)->assertNotFound();
        $this->actingAsApiInOrganization($f['viewAllNoAssignment'], $f['org'])->putJson($url, $body)->assertNotFound();

        DB::table('organization_memberships')
            ->where('user_id', $f['revoked']->id)
            ->where('organization_id', $f['org']->id)
            ->update(['status' => 'revoked']);
        $this->actingAsApiInOrganization($f['revoked'], $f['org'])->putJson($url, $body)->assertForbidden();

        $this->assertSame(0, DB::table('inspection_answers')->count());
    }

    public function test_listado_pagina_ordenado_y_valida_parametros(): void
    {
        $f = $this->fixture();
        $client = fn () => $this->actingAsApiInOrganization($f['owner'], $f['org']);
        $url = '/api/inspections/'.$f['inspection']->id.'/answers';

        foreach (['boolean' => 'true', 'text' => 'Texto', 'number' => '1.5'] as $key => $value) {
            $client()->putJson('/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions'][$key]->id, ['answer' => $value])->assertOk();
            $this->travel(1)->seconds();
        }

        $client()->getJson($url.'?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
        $client()->getJson($url.'?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data');

        foreach (['page=0', 'per_page=0', 'per_page=101', 'page=x'] as $query) {
            $client()->getJson($url.'?'.$query)->assertUnprocessable();
        }
        $client()->getJson($url.'?status=draft')->assertUnprocessable();
        $client()->getJson($url.'?foo=bar')->assertUnprocessable();
    }

    public function test_si_falla_la_auditoria_se_revierte_la_respuesta(): void
    {
        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions']['text']->id;

        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, array $oldValues = [], array $newValues = []): AuditLog
            {
                throw new \RuntimeException('fallo de auditoría');
            }
        });

        $this->actingAsApiInOrganization($f['owner'], $f['org']);
        $this->withoutExceptionHandling();

        try {
            $this->putJson($url, ['answer' => 'Texto']);
            $this->fail('Se esperaba la excepción de auditoría.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('fallo de auditoría', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('inspection_answers')->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('action', 'like', 'inspection.answer_%')
            ->count());
    }

    public function test_los_requisitos_normativos_historicos_no_se_pueden_modificar(): void
    {
        $f = $this->fixture();

        $role = $this->role($f['org'], 'r-'.Str::uuid7());
        app(TenantContext::class)->runAs($f['org']->id, fn () => $f['owner']->assignRole($role));
        RolePermission::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $f['org']->id,
            'role_id' => $role->id,
            'permission' => Permission::NormsManage->value,
        ]);

        $this->actingAsApiInOrganization($f['owner'], $f['org']);

        // La versión está publicada: el contenido histórico queda protegido.
        $this->putJson('/api/requirements/'.$f['requirement']->id, ['description' => 'Cambio'])
            ->assertUnprocessable();

        // Archivada pero alguna vez publicada: la protección se mantiene.
        DB::table('checklist_versions')->where('id', $f['version']->id)->update(['status' => 'archived']);
        $this->putJson('/api/requirements/'.$f['requirement']->id, ['description' => 'Cambio'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('norm_requirements', [
            'id' => $f['requirement']->id,
            'description' => 'Requisito vinculado',
        ]);

        // La lectura histórica conserva el contenido original.
        $this->getJson('/api/inspections/'.$f['inspection']->id.'/checklist')
            ->assertOk()
            ->assertJsonPath('data.sections.0.questions.0.norm_requirements.0.description', 'Requisito vinculado');
    }

    public function test_listado_devuelve_cien_respuestas_por_pagina_por_defecto(): void
    {
        $f = $this->fixture();
        $client = fn () => $this->actingAsApiInOrganization($f['owner'], $f['org']);
        $url = '/api/inspections/'.$f['inspection']->id.'/answers';

        $client()->getJson($url)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 0);

        $client()->putJson('/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions']['text']->id, ['answer' => 'Texto'])->assertOk();

        $client()->getJson($url)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonCount(1, 'data');

        // Un valor explícito dentro del rango se respeta.
        $client()->getJson($url.'?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_la_visibilidad_se_resuelve_antes_de_validar_la_paginacion(): void
    {
        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id.'/answers';

        // El ejecutor no es propietario ni gestiona: la inspección no es
        // visible y debe responder 404 con independencia de la paginación.
        $this->actingAsApiInOrganization($f['executor'], $f['org'])->getJson($url)->assertNotFound();
        $this->actingAsApiInOrganization($f['executor'], $f['org'])->getJson($url.'?foo=bar')->assertNotFound();
        $this->actingAsApiInOrganization($f['executor'], $f['org'])->getJson($url.'?page=0')->assertNotFound();
        $this->actingAsApiInOrganization($f['executor'], $f['org'])->getJson($url.'?per_page=101')->assertNotFound();

        // Para una inspección visible, los parámetros inválidos sí se validan.
        $this->actingAsApiInOrganization($f['owner'], $f['org'])->getJson($url.'?foo=bar')->assertUnprocessable();
        $this->actingAsApiInOrganization($f['owner'], $f['org'])->getJson($url.'?page=0')->assertUnprocessable();
    }

    public function test_los_parametros_de_consulta_no_permiten_eludir_el_cuerpo_json(): void
    {
        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions']['text']->id;
        $client = $this->actingAsApiInOrganization($f['owner'], $f['org']);

        // Un parámetro de consulta no puede aportar la respuesta.
        $client->putJson($url.'?answer=Texto', [])->assertUnprocessable();
        $this->assertSame(0, DB::table('inspection_answers')->count());

        // NiObservation ni otros campos pueden llegar por la consulta.
        $client->putJson($url.'?observation=nota', [])->assertUnprocessable();
        $this->assertSame(0, DB::table('inspection_answers')->count());

        // La escritura no admite parámetros de consulta en absoluto.
        $client->putJson($url.'?foo=bar', ['answer' => 'Texto'])->assertUnprocessable();
        $client->deleteJson($url.'?foo=bar')->assertUnprocessable();
        $this->assertSame(0, DB::table('inspection_answers')->count());

        // El cuerpo JSON válido sí se acepta y conserva los espacios.
        $client->putJson($url, ['answer' => '  Texto con espacios  '])
            ->assertOk()
            ->assertJsonPath('data.answer', '  Texto con espacios  ');
    }

    public function test_un_error_de_integridad_distinto_de_evidencias_no_se_traduce_a_422(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            // En MariaDB el DDL provoca un COMMIT implícito y contaminaría el
            // esquema compartido de la suite. La cobertura equivalente está en
            // InspectionAnswerConcurrencyMariaDbTest.
            $this->markTestSkipped('El escenario requiere el DDL transaccional de SQLite.');
        }

        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions']['text']->id;
        $client = $this->actingAsApiInOrganization($f['owner'], $f['org']);

        $answerId = $client->putJson($url, ['answer' => 'Texto'])->assertOk()->json('data.id');

        // Segunda tabla que referencia `inspection_answers`: la violación de
        // esa FK no es un bloqueo por evidencias y no debe traducirse a 422.
        // El nombre contiene «evidences» a propósito para detectar una
        // comparación por subcadena del mensaje del motor.
        DB::statement('CREATE TABLE archived_evidences (
            id TEXT NOT NULL PRIMARY KEY,
            inspection_answer_id TEXT NOT NULL,
            FOREIGN KEY (inspection_answer_id) REFERENCES inspection_answers (id) ON DELETE RESTRICT
        )');
        DB::table('archived_evidences')->insert([
            'id' => (string) Str::uuid7(),
            'inspection_answer_id' => $answerId,
        ]);

        $client->deleteJson($url)->assertStatus(500);

        $this->assertDatabaseHas('inspection_answers', ['id' => $answerId]);
        $this->assertSame(1, DB::table('archived_evidences')->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('action', 'inspection.answer_deleted')
            ->count());
    }

    public function test_si_falla_la_auditoria_se_revierten_la_actualizacion_y_el_borrado(): void
    {
        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id.'/answers/'.$f['questions']['text']->id;

        $answerId = $this->actingAsApiInOrganization($f['owner'], $f['org'])
            ->putJson($url, ['answer' => 'Texto'])
            ->assertOk()
            ->json('data.id');

        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, array $oldValues = [], array $newValues = []): AuditLog
            {
                throw new \RuntimeException('fallo de auditoría');
            }
        });

        $this->actingAsApiInOrganization($f['owner'], $f['org']);
        $this->withoutExceptionHandling();

        try {
            $this->putJson($url, ['answer' => 'Otro texto']);
            $this->fail('Se esperaba la excepción de auditoría.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('fallo de auditoría', $exception->getMessage());
        }

        $this->assertDatabaseHas('inspection_answers', ['id' => $answerId, 'answer' => 'Texto']);

        try {
            $this->deleteJson($url);
            $this->fail('Se esperaba la excepción de auditoría.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('fallo de auditoría', $exception->getMessage());
        }

        $this->assertDatabaseHas('inspection_answers', ['id' => $answerId]);
        $this->assertSame(0, DB::table('audit_logs')
            ->whereIn('action', ['inspection.answer_updated', 'inspection.answer_deleted'])
            ->count());
    }
}
