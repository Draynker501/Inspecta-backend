<?php

namespace Tests\Feature\Api;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Models\Organization;
use App\Models\Question;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class InspectionSummaryTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function assignInspectionPermission(
        User $user,
        Organization $organization,
        Permission $permission
    ): void {
        app(TenantContext::class)->runAs(
            $organization->id,
            function () use ($user, $organization, $permission): void {
                $role = $this->role(
                    $organization,
                    'Gestor '.Str::uuid7()
                );

                $user->assignRole($role);

                RolePermission::firstOrCreate([
                    'role_id' => $role->id,
                    'organization_id' => $organization->id,
                    'permission' => $permission->value,
                ], [
                    'id' => (string) Str::uuid7(),
                ]);
            }
        );
    }

    private function prepareInspection(
        string $code = 'INSP-SUMMARY',
        bool $withRequiredQuestions = true,
        ?Organization $organization = null,
        ?User $user = null,
        bool $assignLocation = true
    ): array {
        $organization ??= $this->clientOrganization($code);

        $user ??= $this->user($organization);

        return app(TenantContext::class)->runAs(
            $organization->id,
            function () use (
                $organization,
                $user,
                $code,
                $withRequiredQuestions,
                $assignLocation
            ): array {
                $plant = $this->plant(
                    $organization,
                    "{$code}-P",
                    ['name' => 'Planta Norte']
                );

                $area = $this->area(
                    $plant,
                    "{$code}-A",
                    ['name' => 'Área de Almacén']
                );

                $line = $this->productionLine(
                    $area,
                    "{$code}-L",
                    ['name' => 'Línea de producción 1']
                );

                if ($assignLocation) {
                    DB::table('user_plants')->insert([
                        'id' => (string) Str::uuid7(),
                        'organization_id' => $organization->id,
                        'user_id' => $user->id,
                        'plant_id' => $plant->id,
                    ]);
                }

                $checklist = Checklist::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->id,
                    'name' => 'Seguridad industrial y EPP',
                    'status' => true,
                ]);

                $version = ChecklistVersion::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->id,
                    'checklist_id' => $checklist->id,
                    'version' => '1.0',
                    'status' => 'draft',
                    'created_by' => $user->id,
                ]);

                $section = ChecklistSection::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->id,
                    'checklist_version_id' => $version->id,
                    'name' => 'Evaluación general',
                    'display_order' => 1,
                ]);

                $questions = [];

                for ($index = 1; $index <= 5; $index++) {
                    $questions[] = Question::create([
                        'id' => (string) Str::uuid7(),
                        'organization_id' => $organization->id,
                        'checklist_section_id' => $section->id,
                        'question_text' => "Pregunta {$index}",
                        'answer_type' => 'boolean',
                        'display_order' => $index,
                        'required' => $withRequiredQuestions
                            && $index !== 4,
                        'allows_evidence' => false,
                        'status' => $index !== 5,
                    ]);
                }

                $version->update([
                    'status' => 'published',
                    'published_at' => now(),
                ]);

                $inspection = Inspection::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'checklist_version_id' => $version->id,
                    'plant_id' => $plant->id,
                    'area_id' => $area->id,
                    'production_line_id' => $line->id,
                    'status' => 'draft',
                    'started_at' => '2026-11-19 10:00:00',
                ]);

                return [
                    'organization' => $organization,
                    'user' => $user,
                    'plant' => $plant,
                    'area' => $area,
                    'line' => $line,
                    'checklist' => $checklist,
                    'version' => $version,
                    'section' => $section,
                    'questions' => $questions,
                    'inspection' => $inspection,
                ];
            }
        );
    }

    private function authenticateFixture(array $fixture): void
    {
        $this->assignInspectionPermission(
            $fixture['user'],
            $fixture['organization'],
            Permission::InspectionsManage
        );

        $this->actingAsApiInOrganization(
            $fixture['user'],
            $fixture['organization']
        );
    }

    private function answerQuestion(
        array $fixture,
        int $questionIndex,
        string $answer = 'true'
    ): void {
        app(TenantContext::class)->runAs(
            $fixture['organization']->id,
            function () use (
                $fixture,
                $questionIndex,
                $answer
            ): void {
                InspectionAnswer::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $fixture['organization']->id,
                    'inspection_id' => $fixture['inspection']->id,
                    'question_id' => $fixture['questions'][$questionIndex]->id,
                    'checklist_version_id' => $fixture['version']->id,
                    'checklist_section_id' => $fixture['section']->id,
                    'answer' => $answer,
                ]);
            }
        );
    }

    public function test_list_includes_inspection_summary(): void
    {
        $fixture = $this->prepareInspection();

        $this->authenticateFixture($fixture);

        $this->getJson('/api/inspections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                $fixture['inspection']->id
            )
            ->assertJsonPath(
                'data.0.inspector_name',
                $fixture['user']->name
            )
            ->assertJsonPath(
                'data.0.plant_name',
                'Planta Norte'
            )
            ->assertJsonPath(
                'data.0.area_name',
                'Área de Almacén'
            )
            ->assertJsonPath(
                'data.0.production_line_name',
                'Línea de producción 1'
            )
            ->assertJsonPath(
                'data.0.checklist_name',
                'Seguridad industrial y EPP'
            )
            ->assertJsonPath(
                'data.0.checklist_version',
                '1.0'
            )
            ->assertJsonPath('data.0.progress', 0)
            ->assertJsonPath('data.0.answered_questions', 0)
            ->assertJsonPath('data.0.required_questions', 3)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_detail_includes_same_summary_as_list(): void
    {
        $fixture = $this->prepareInspection();

        $this->authenticateFixture($fixture);

        $id = $fixture['inspection']->id;

        $list = $this->getJson('/api/inspections')
            ->assertOk()
            ->json('data.0');

        $detail = $this->getJson("/api/inspections/{$id}")
            ->assertOk()
            ->json('data');

        foreach ([
            'inspector_name',
            'plant_name',
            'area_name',
            'production_line_name',
            'checklist_name',
            'checklist_version',
            'progress',
            'answered_questions',
            'required_questions',
        ] as $field) {
            $this->assertArrayHasKey($field, $detail);

            $this->assertSame(
                $list[$field],
                $detail[$field]
            );
        }
    }

    public function test_partial_progress_is_calculated_correctly(): void
    {
        $fixture = $this->prepareInspection();

        $this->answerQuestion($fixture, 0, 'false');

        $this->authenticateFixture($fixture);

        $id = $fixture['inspection']->id;

        $this->getJson("/api/inspections/{$id}")
            ->assertOk()
            ->assertJsonPath('data.answered_questions', 1)
            ->assertJsonPath('data.required_questions', 3)
            ->assertJsonPath('data.progress', 33.33);
    }

    public function test_all_required_answers_produce_full_progress(): void
    {
        $fixture = $this->prepareInspection();

        $this->answerQuestion($fixture, 0);
        $this->answerQuestion($fixture, 1);
        $this->answerQuestion($fixture, 2);

        $this->authenticateFixture($fixture);

        $this->getJson('/api/inspections')
            ->assertOk()
            ->assertJsonPath('data.0.answered_questions', 3)
            ->assertJsonPath('data.0.required_questions', 3)
            ->assertJsonPath('data.0.progress', 100);
    }

    public function test_optional_and_inactive_questions_do_not_affect_progress(): void
    {
        $fixture = $this->prepareInspection();

        // Pregunta opcional.
        $this->answerQuestion($fixture, 3);

        // Pregunta inactiva.
        $this->answerQuestion($fixture, 4);

        $this->authenticateFixture($fixture);

        $this->getJson('/api/inspections')
            ->assertOk()
            ->assertJsonPath('data.0.answered_questions', 0)
            ->assertJsonPath('data.0.required_questions', 3)
            ->assertJsonPath('data.0.progress', 0);
    }

    public function test_not_applicable_answers_are_not_counted(): void
    {
        $fixture = $this->prepareInspection();

        $this->answerQuestion($fixture, 0, '__NA__');
        $this->answerQuestion($fixture, 1, 'true');

        $this->authenticateFixture($fixture);

        $this->getJson('/api/inspections')
            ->assertOk()
            ->assertJsonPath('data.0.answered_questions', 1)
            ->assertJsonPath('data.0.required_questions', 3)
            ->assertJsonPath('data.0.progress', 33.33);
    }

    public function test_progress_is_null_without_required_questions(): void
    {
        $fixture = $this->prepareInspection(
            withRequiredQuestions: false
        );

        $this->answerQuestion($fixture, 0);

        $this->authenticateFixture($fixture);

        $this->getJson('/api/inspections')
            ->assertOk()
            ->assertJsonPath('data.0.answered_questions', 0)
            ->assertJsonPath('data.0.required_questions', 0)
            ->assertJsonPath('data.0.progress', null);
    }

    public function test_summary_supports_inspections_without_production_line(): void
    {
        $fixture = $this->prepareInspection();

        app(TenantContext::class)->runAs(
            $fixture['organization']->id,
            fn () => $fixture['inspection']->update([
                'production_line_id' => null,
            ])
        );

        $this->authenticateFixture($fixture);

        $this->getJson('/api/inspections')
            ->assertOk()
            ->assertJsonPath('data.0.production_line_id', null)
            ->assertJsonPath('data.0.production_line_name', null)
            ->assertJsonPath(
                'data.0.area_name',
                'Área de Almacén'
            );
    }

    public function test_dashboard_and_inspections_share_the_same_progress(): void
    {
        $fixture = $this->prepareInspection();

        $this->answerQuestion($fixture, 0);
        $this->answerQuestion($fixture, 1);

        $this->authenticateFixture($fixture);

        $inspection = $this->getJson('/api/inspections')
            ->assertOk()
            ->assertJsonPath('data.0.progress', 66.67)
            ->json('data.0');

        $dashboard = $this->getJson(
            '/api/dashboard?month=2026-11'
        )
            ->assertOk()
            ->assertJsonCount(1, 'recent_inspections')
            ->json('recent_inspections.0');

        foreach ([
            'id',
            'checklist_version_id',
            'checklist_name',
            'checklist_version',
            'plant_name',
            'area_name',
            'production_line_name',
            'progress',
            'answered_questions',
            'required_questions',
        ] as $field) {
            $this->assertSame(
                $inspection[$field],
                $dashboard[$field]
            );
        }
    }

    public function test_inspection_summary_respects_tenant_isolation(): void
    {
        $own = $this->prepareInspection('SUMMARY-OWN');

        $foreign = $this->prepareInspection('SUMMARY-FOREIGN');

        $this->authenticateFixture($own);

        $this->getJson('/api/inspections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                $own['inspection']->id
            )
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing([
                'id' => $foreign['inspection']->id,
            ]);

        $foreignId = $foreign['inspection']->id;

        $this->getJson("/api/inspections/{$foreignId}")
            ->assertNotFound();
    }

    public function test_summary_excludes_unauthorized_locations(): void
    {
        $allowed = $this->prepareInspection('SUMMARY-ALLOWED');

        $restricted = $this->prepareInspection(
            code: 'SUMMARY-RESTRICTED',
            organization: $allowed['organization'],
            user: $allowed['user'],
            assignLocation: false
        );

        $this->authenticateFixture($allowed);

        $this->getJson('/api/inspections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                $allowed['inspection']->id
            )
            ->assertJsonMissing([
                'id' => $restricted['inspection']->id,
            ]);
    }

    public function test_inspection_summary_preserves_pagination(): void
    {
        $first = $this->prepareInspection('SUMMARY-PAGE-A');

        $second = $this->prepareInspection(
            code: 'SUMMARY-PAGE-B',
            organization: $first['organization'],
            user: $first['user']
        );

        $this->authenticateFixture($first);

        $pageOne = $this->getJson(
            '/api/inspections?page=1&per_page=1'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.progress', 0)
            ->json('data.0.id');

        $pageTwo = $this->getJson(
            '/api/inspections?page=2&per_page=1'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.progress', 0)
            ->json('data.0.id');

        $this->assertNotSame($pageOne, $pageTwo);

        $this->assertEqualsCanonicalizing(
            [
                $first['inspection']->id,
                $second['inspection']->id,
            ],
            [$pageOne, $pageTwo]
        );
    }
}
