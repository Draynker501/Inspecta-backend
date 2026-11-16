<?php

namespace Tests\Feature\Checklists;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\OrganizationMembership;
use App\Models\Question;
use App\Models\RolePermission;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ChecklistStructureReadTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_detalle_de_seccion_y_pregunta_exige_permiso_y_jerarquia_del_tenant(): void
    {
        $this->getJson('/api/sections/missing')->assertUnauthorized();
        $this->getJson('/api/questions/missing')->assertUnauthorized();

        $org = $this->organization('DETAIL-A');
        $other = $this->organization('DETAIL-B');
        $user = $this->user($org);
        $otherUser = $this->user($other);
        $role = $this->role($org, 'detail-reader');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        [$checklist, $version, $section, $question] = $this->structure($org->id, $user->id);
        [$foreignChecklist, $foreignVersion, $foreignSection, $foreignQuestion] = $this->structure($other->id, $otherUser->id);
        $this->actingAsApiInOrganization($user, $org);

        foreach (["/api/sections/{$section->id}", "/api/questions/{$question->id}", '/api/sections/missing', '/api/questions/missing'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        RolePermission::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'role_id' => $role->id, 'permission' => Permission::ChecklistsView->value]);
        $this->getJson("/api/sections/{$section->id}")->assertOk()->assertJsonPath('data.id', $section->id);
        $this->getJson("/api/questions/{$question->id}")->assertOk()->assertJsonPath('data.id', $question->id)->assertJsonPath('data.answer_type', 'yes_no');
        $this->getJson('/api/sections/missing')->assertNotFound();
        $this->getJson('/api/questions/missing')->assertNotFound();
        $this->getJson("/api/sections/{$foreignSection->id}")->assertNotFound();
        $this->getJson("/api/questions/{$foreignQuestion->id}")->assertNotFound();

        DB::table('questions')->where('id', $foreignQuestion->id)->update(['organization_id' => $org->id]);
        $this->getJson("/api/questions/{$foreignQuestion->id}")->assertNotFound();
        DB::table('checklist_sections')->where('id', $foreignSection->id)->update(['organization_id' => $org->id]);
        $this->getJson("/api/sections/{$foreignSection->id}")->assertNotFound();
        $this->getJson("/api/questions/{$foreignQuestion->id}")->assertNotFound();
        DB::table('checklist_versions')->where('id', $foreignVersion->id)->update(['organization_id' => $org->id]);
        $this->getJson("/api/sections/{$foreignSection->id}")->assertNotFound();
        $this->getJson("/api/questions/{$foreignQuestion->id}")->assertNotFound();

        Gate::before(fn ($actor, $ability) => $ability === 'view' ? false : null);
        $this->getJson("/api/sections/{$section->id}")->assertForbidden();
        $this->getJson("/api/questions/{$question->id}")->assertForbidden();
    }

    public function test_las_seis_rutas_requieren_autenticacion(): void
    {
        $this->getJson('/api/checklists')->assertUnauthorized();
        $this->postJson('/api/checklists', [])->assertUnauthorized();
        $this->getJson('/api/checklists/missing')->assertUnauthorized();
        $this->getJson('/api/checklists/missing/versions')->assertUnauthorized();
        $this->postJson('/api/checklists/missing/versions', [])->assertUnauthorized();
        $this->getJson('/api/versions/missing/sections')->assertUnauthorized();
        $this->getJson('/api/sections/missing/questions')->assertUnauthorized();
    }

    public function test_lectura_de_jerarquia_exige_permiso_y_no_revela_otros_tenants(): void
    {
        $org = $this->organization('STRUCT-A');
        $other = $this->organization('STRUCT-B');
        $user = $this->user($org);
        $otherUser = $this->user($other);
        $role = $this->role($org, 'structure-reader');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));

        [$checklist, $version, $section, $question] = $this->structure($org->id, $user->id);
        [$foreignChecklist, $foreignVersion, $foreignSection, $foreignQuestion] = $this->structure($other->id, $otherUser->id);
        $this->actingAsApiInOrganization($user, $org);

        $this->getJson("/api/checklists/{$checklist->id}/versions?per_page=0")->assertForbidden();
        $this->getJson("/api/versions/{$foreignVersion->id}/sections")->assertForbidden();
        $this->getJson("/api/sections/{$foreignSection->id}/questions")->assertForbidden();
        RolePermission::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'role_id' => $role->id, 'permission' => Permission::ChecklistsView->value]);

        $this->getJson("/api/checklists/{$checklist->id}/versions")->assertOk()->assertJsonPath('data.0.id', $version->id);
        $this->getJson("/api/versions/{$version->id}/sections")->assertOk()->assertJsonPath('data.0.id', $section->id);
        // La columna histórica es string sin CHECK; el lector no debe inventar
        // una conversión de tipos antiguos a los seis valores del contrato.
        $this->getJson("/api/sections/{$section->id}/questions")->assertOk()
            ->assertJsonPath('data.0.id', $question->id)->assertJsonPath('data.0.answer_type', 'yes_no');
        $this->getJson("/api/checklists/{$foreignChecklist->id}/versions")->assertNotFound();
        $this->getJson("/api/versions/{$foreignVersion->id}/sections")->assertNotFound();
        $this->getJson("/api/sections/{$foreignSection->id}/questions")->assertNotFound();
        $this->getJson("/api/checklists/{$checklist->id}/versions?per_page=0")->assertUnprocessable();

        // Las FK históricas no son compuestas: las lecturas deben cerrar también
        // ante filas incoherentes insertadas fuera de las invariantes Eloquent.
        DB::table('checklist_versions')->where('id', $foreignVersion->id)->update(['organization_id' => $org->id]);
        DB::table('checklist_sections')->where('id', $foreignSection->id)->update(['organization_id' => $org->id]);
        $this->getJson("/api/versions/{$foreignVersion->id}/sections")->assertNotFound();
        $this->getJson("/api/sections/{$foreignSection->id}/questions")->assertNotFound();

        DB::table('questions')->where('id', $foreignQuestion->id)->update(['checklist_section_id' => $section->id, 'display_order' => 2]);
        $this->getJson("/api/sections/{$section->id}/questions")->assertOk()->assertJsonCount(1, 'data');

        OrganizationMembership::where('organization_id', $org->id)->where('user_id', $user->id)
            ->update(['status' => OrganizationMembership::STATUS_REVOKED]);
        $this->getJson('/api/checklists')->assertForbidden();
        $this->postJson('/api/checklists', ['name' => 'No'])->assertForbidden();
        $this->getJson("/api/checklists/{$checklist->id}")->assertForbidden();
        $this->getJson("/api/checklists/{$checklist->id}/versions")->assertForbidden();
        $this->postJson("/api/checklists/{$checklist->id}/versions", ['version' => 'no'])->assertForbidden();
        $this->getJson("/api/versions/{$version->id}/sections")->assertForbidden();
        $this->getJson("/api/sections/{$section->id}/questions")->assertForbidden();
    }

    private function structure(string $organizationId, string $userId): array
    {
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $organizationId, 'name' => 'Checklist', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $organizationId, 'checklist_id' => $checklist->id, 'version' => '1', 'status' => 'draft', 'created_by' => $userId]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $organizationId, 'checklist_version_id' => $version->id, 'name' => 'Sección', 'display_order' => 1]);
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $organizationId, 'checklist_section_id' => $section->id, 'question_text' => 'Pregunta', 'answer_type' => 'yes_no', 'display_order' => 1, 'status' => true]);

        return [$checklist, $version, $section, $question];
    }
}
