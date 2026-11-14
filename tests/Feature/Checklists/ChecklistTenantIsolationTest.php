<?php

namespace Tests\Feature\Checklists;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChecklistFixtures;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ChecklistTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;
    use ChecklistFixtures;

    public function test_checklist_se_crea_en_organizacion_activa(): void
    {
        $org = $this->organization('ORG-A');
        $user = $this->user($org);

        $this->loginForChecklistTests($org, $user);

        $id = $this->createChecklist();

        $this->assertDatabaseHas('checklists', [
            'id' => $id,
            'organization_id' => $org->id,
        ]);
    }

    public function test_usuario_no_puede_ver_checklist_ajeno(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        $userA = $this->user($orgA);
        $userB = $this->user($orgB);

        $this->loginForChecklistTests($orgB, $userB);
        $checklistB = $this->createChecklist('Checklist B');

        app(TenantContext::class)->clear();

        $this->loginForChecklistTests($orgA, $userA);

        $this->getJson("/api/checklists/{$checklistB}")
            ->assertNotFound();
    }

    public function test_listado_no_muestra_checklists_ajenos(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        $userA = $this->user($orgA);
        $userB = $this->user($orgB);

        $this->loginForChecklistTests($orgB, $userB);
        $checklistB = $this->createChecklist('Checklist B');

        app(TenantContext::class)->clear();

        $this->loginForChecklistTests($orgA, $userA);
        $checklistA = $this->createChecklist('Checklist A');

        $this->getJson('/api/checklists')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $checklistA])
            ->assertJsonMissing(['id' => $checklistB]);
    }

    public function test_usuario_no_puede_modificar_checklist_ajeno(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        $userA = $this->user($orgA);
        $userB = $this->user($orgB);

        $this->loginForChecklistTests($orgB, $userB);
        $checklistB = $this->createChecklist('Checklist original');

        app(TenantContext::class)->clear();

        $this->loginForChecklistTests($orgA, $userA);

        $this->putJson("/api/checklists/{$checklistB}", [
            'name' => 'Nombre alterado',
        ])->assertNotFound();

        $this->assertSame(
            'Checklist original',
            DB::table('checklists')
                ->where('id', $checklistB)
                ->value('name')
        );
    }

    public function test_usuario_no_puede_publicar_version_ajena(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        $userA = $this->user($orgA);
        $userB = $this->user($orgB);

        $this->loginForChecklistTests($orgB, $userB);

        $checklistB = $this->createChecklist('Checklist B');
        $versionB = $this->createVersion($checklistB);

        app(TenantContext::class)->clear();

        $this->loginForChecklistTests($orgA, $userA);

        $this->patchJson(
            "/api/checklists/{$checklistB}/versions/{$versionB}/status",
            ['status' => 'published']
        )->assertNotFound();

        $this->assertDatabaseHas('checklist_versions', [
            'id' => $versionB,
            'status' => 'draft',
        ]);
    }
}