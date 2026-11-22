<?php

namespace Tests\Feature\Norms;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Inspection;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Question;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * PWA-FUNC-01.3.1 — Opción B: toda información normativa exige `norms.view`,
 * incluso embebida en checklists e inspecciones.
 */
class NormRequirementChecklistAuthorizationTest extends TestCase
{
    use RefreshDatabase, TenantFixtures;

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $org = $this->clientOrganization('NR-01.3.1');
        app(TenantContext::class)->set($org->id);

        $plant = $this->plant($org, 'P1');
        $area = $this->area($plant, 'A1');

        $checklist = Checklist::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'name' => 'Checklist normativo', 'status' => true,
        ]);
        $version = ChecklistVersion::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'checklist_id' => $checklist->id, 'created_by' => $this->user($org)->id,
            'version' => '1', 'status' => 'published', 'published_at' => now(),
        ]);
        $section = ChecklistSection::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'checklist_version_id' => $version->id, 'name' => 'S1', 'display_order' => 1,
        ]);
        $linkedQuestion = Question::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'checklist_section_id' => $section->id, 'question_text' => 'Con vínculo',
            'answer_type' => 'boolean', 'display_order' => 1, 'required' => true,
            'allows_evidence' => false, 'status' => true,
        ]);
        $plainQuestion = Question::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'checklist_section_id' => $section->id, 'question_text' => 'Sin vínculo',
            'answer_type' => 'text', 'display_order' => 2, 'required' => false,
            'allows_evidence' => false, 'status' => true,
        ]);

        $norm = Norm::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'code' => 'NR-01', 'name' => 'Norma 01', 'status' => true,
        ]);
        $requirement = NormRequirement::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'norm_id' => $norm->id, 'code' => 'REQ-1', 'section' => '4.1',
            'description' => 'Requisito vinculado',
        ]);
        $linkId = (string) Str::uuid7();
        DB::table('question_norm_requirement')->insert([
            'id' => $linkId, 'organization_id' => $org->id,
            'question_id' => $linkedQuestion->id, 'norm_requirement_id' => $requirement->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $owner = $this->userWith($org, [Permission::InspectionsExecute, Permission::ChecklistsView, Permission::NormsView], $area);
        $manager = $this->userWith($org, [Permission::InspectionsManage, Permission::ChecklistsView], $area);
        $normsOnly = $this->userWith($org, [Permission::NormsView], null);

        $inspection = $this->inspection($owner, $version, $plant, $area);

        return compact(
            'org', 'plant', 'area', 'checklist', 'version', 'section',
            'linkedQuestion', 'plainQuestion', 'norm', 'requirement', 'linkId',
            'owner', 'manager', 'normsOnly', 'inspection',
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
                'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
                'role_id' => $role->id, 'permission' => $permission->value,
            ]);
        }
        if ($area !== null) {
            DB::table('user_areas')->insert([
                'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
                'user_id' => $user->id, 'area_id' => $area->id,
            ]);
        }

        return $user;
    }

    private function inspection(User $user, ChecklistVersion $version, object $plant, object $area): Inspection
    {
        app(TenantContext::class)->set($version->organization_id);

        return Inspection::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $version->organization_id,
            'user_id' => $user->id, 'checklist_version_id' => $version->id,
            'plant_id' => $plant->id, 'area_id' => $area->id, 'status' => 'draft',
            'started_at' => now(),
        ]);
    }

    public function test_checklist_expone_normativa_solo_con_norms_view(): void
    {
        $f = $this->fixture();
        $url = '/api/inspections/'.$f['inspection']->id.'/checklist';

        $this->actingAsApiInOrganization($f['owner'], $f['org'])->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.sections.0.questions.0.norm_requirements.0.code', 'REQ-1')
            ->assertJsonPath('data.sections.0.questions.0.norm_requirements.0.id', $f['requirement']->id)
            ->assertJsonPath('data.sections.0.questions.0.norm_requirements.0.link_id', $f['linkId'])
            ->assertJsonPath('data.sections.0.questions.1.norm_requirements', []);

        $this->actingAsApiInOrganization($f['manager'], $f['org'])->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.sections.0.questions.0.norm_requirements', [])
            ->assertJsonPath('data.sections.0.questions.1.norm_requirements', []);
    }

    public function test_active_version_expone_normativa_solo_con_norms_view(): void
    {
        $f = $this->fixture();
        $url = '/api/checklists/'.$f['checklist']->id.'/active-version';

        $this->actingAsApiInOrganization($f['owner'], $f['org'])->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.active_version.sections.0.questions.0.norm_requirements.0.code', 'REQ-1');

        $this->actingAsApiInOrganization($f['manager'], $f['org'])->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.active_version.sections.0.questions.0.norm_requirements', []);
    }

    public function test_endpoints_normativos_directos_exigen_norms_view(): void
    {
        $f = $this->fixture();

        $this->actingAsApiInOrganization($f['manager'], $f['org'])
            ->getJson('/api/questions/'.$f['linkedQuestion']->id.'/norm-requirements')->assertForbidden();
        $this->actingAsApiInOrganization($f['manager'], $f['org'])
            ->getJson('/api/requirements/'.$f['requirement']->id)->assertForbidden();
        $this->actingAsApiInOrganization($f['manager'], $f['org'])
            ->getJson('/api/norms/'.$f['norm']->id)->assertForbidden();

        $this->actingAsApiInOrganization($f['owner'], $f['org'])
            ->getJson('/api/questions/'.$f['linkedQuestion']->id.'/norm-requirements')
            ->assertOk()->assertJsonPath('data.0.code', 'REQ-1');
        $this->actingAsApiInOrganization($f['owner'], $f['org'])
            ->getJson('/api/requirements/'.$f['requirement']->id)->assertOk();
        $this->actingAsApiInOrganization($f['owner'], $f['org'])
            ->getJson('/api/norms/'.$f['norm']->id)->assertOk();
    }

    public function test_sin_permiso_operativo_no_hay_acceso_adicional(): void
    {
        $f = $this->fixture();

        $this->actingAsApiInOrganization($f['normsOnly'], $f['org'])
            ->getJson('/api/inspections/'.$f['inspection']->id.'/checklist')->assertForbidden();
        $this->actingAsApiInOrganization($f['normsOnly'], $f['org'])
            ->getJson('/api/checklists/'.$f['checklist']->id.'/active-version')->assertForbidden();
    }

    public function test_aislamiento_organizacional_devuelve_404(): void
    {
        $f = $this->fixture();
        $other = $this->clientOrganization('NR-OTHER');
        app(TenantContext::class)->set($other->id);
        $otherNorm = Norm::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $other->id,
            'code' => 'NR-O', 'name' => 'Ajena', 'status' => true,
        ]);
        $otherRequirement = NormRequirement::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $other->id,
            'norm_id' => $otherNorm->id, 'code' => 'REQ-O', 'description' => 'Ajeno',
        ]);
        $otherPlant = $this->plant($other, 'P1');
        $otherArea = $this->area($otherPlant, 'A1');
        $otherChecklist = Checklist::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $other->id,
            'name' => 'Ajeno', 'status' => true,
        ]);
        $otherVersion = ChecklistVersion::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $other->id,
            'checklist_id' => $otherChecklist->id, 'created_by' => $this->user($other)->id,
            'version' => '1', 'status' => 'published', 'published_at' => now(),
        ]);
        $foreignUser = $this->userWith($other, [Permission::InspectionsExecute, Permission::ChecklistsView, Permission::NormsView], $otherArea);
        $foreignInspection = $this->inspection($foreignUser, $otherVersion, $otherPlant, $otherArea);

        $this->actingAsApiInOrganization($f['owner'], $f['org'])
            ->getJson('/api/inspections/'.$foreignInspection->id.'/checklist')->assertNotFound();
        $this->actingAsApiInOrganization($f['owner'], $f['org'])
            ->getJson('/api/requirements/'.$otherRequirement->id)->assertNotFound();
        $this->actingAsApiInOrganization($f['owner'], $f['org'])
            ->getJson('/api/norms/'.$otherNorm->id)->assertNotFound();
    }

    public function test_cambio_de_tenant_evalua_segun_contexto_activo(): void
    {
        $orgA = $this->clientOrganization('NR-TA');
        $orgB = $this->clientOrganization('NR-TB');

        $user = $this->user($orgA);
        OrganizationMembership::firstOrCreate(
            ['user_id' => $user->id, 'organization_id' => $orgB->id],
            ['id' => (string) Str::uuid7(), 'status' => OrganizationMembership::STATUS_ACTIVE],
        );

        $plantA = $this->plant($orgA, 'P1');
        $areaA = $this->area($plantA, 'A1');
        $plantB = $this->plant($orgB, 'P1');
        $areaB = $this->area($plantB, 'A1');

        // En A el usuario ve normativa; en B no.
        $this->grant($orgA, $user, [Permission::InspectionsExecute, Permission::ChecklistsView, Permission::NormsView], $areaA);
        $this->grant($orgB, $user, [Permission::InspectionsExecute, Permission::ChecklistsView], $areaB);

        [$versionA, $questionA, $normA, $requirementA] = $this->publishedVersionWithLink($orgA, 'A');
        [$versionB, $questionB] = $this->publishedVersionWithLink($orgB, 'B');

        $inspectionA = $this->inspection($user, $versionA, $plantA, $areaA);
        $inspectionB = $this->inspection($user, $versionB, $plantB, $areaB);

        $this->actingAsApiInOrganization($user, $orgA)
            ->getJson('/api/inspections/'.$inspectionA->id.'/checklist')
            ->assertOk()->assertJsonPath('data.sections.0.questions.0.norm_requirements.0.code', 'REQ-A');

        $this->actingAsApiInOrganization($user, $orgB)
            ->getJson('/api/inspections/'.$inspectionB->id.'/checklist')
            ->assertOk()->assertJsonPath('data.sections.0.questions.0.norm_requirements', []);
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function grant(Organization $org, User $user, array $permissions, ?object $area): void
    {
        $role = $this->role($org, 'r-'.Str::uuid7());
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        foreach ($permissions as $permission) {
            RolePermission::create([
                'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
                'role_id' => $role->id, 'permission' => $permission->value,
            ]);
        }
        if ($area !== null) {
            DB::table('user_areas')->insert([
                'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
                'user_id' => $user->id, 'area_id' => $area->id,
            ]);
        }
    }

    /**
     * @return array{0: ChecklistVersion, 1: Question, 2: Norm, 3: NormRequirement}
     */
    private function publishedVersionWithLink(Organization $org, string $tag): array
    {
        app(TenantContext::class)->set($org->id);
        $checklist = Checklist::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'name' => 'Checklist '.$tag, 'status' => true,
        ]);
        $version = ChecklistVersion::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'checklist_id' => $checklist->id, 'created_by' => $this->user($org)->id,
            'version' => '1', 'status' => 'published', 'published_at' => now(),
        ]);
        $section = ChecklistSection::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'checklist_version_id' => $version->id, 'name' => 'S', 'display_order' => 1,
        ]);
        $question = Question::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'checklist_section_id' => $section->id, 'question_text' => 'Q '.$tag,
            'answer_type' => 'boolean', 'display_order' => 1, 'required' => true,
            'allows_evidence' => false, 'status' => true,
        ]);
        $norm = Norm::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'code' => 'NR-'.$tag, 'name' => 'Norma '.$tag, 'status' => true,
        ]);
        $requirement = NormRequirement::create([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'norm_id' => $norm->id, 'code' => 'REQ-'.$tag, 'description' => 'Requisito '.$tag,
        ]);
        DB::table('question_norm_requirement')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $org->id,
            'question_id' => $question->id, 'norm_requirement_id' => $requirement->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$version, $question, $norm, $requirement];
    }
}
