<?php

namespace Tests\Feature\Norms;

use App\Authorization\Permission;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\RolePermission;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class NormReadTest extends TestCase
{
    use RefreshDatabase, TenantFixtures;

    public function test_permiso_precede_resolucion_y_validacion(): void
    {
        $org = $this->organization('NORM-AUTH');
        $user = $this->user($org);
        $this->getJson('/api/norms')->assertUnauthorized();
        $this->actingAsApiInOrganization($user, $org);
        foreach (['/api/norms?per_page=0', '/api/norms/missing', '/api/norms/missing/requirements?per_page=0', '/api/requirements/missing'] as $uri) {
            $this->getJson($uri)->assertForbidden();
        }
    }

    public function test_lecturas_paginadas_y_aislamiento_organizacional(): void
    {
        $org = $this->organization('NORM-A');
        $other = $this->organization('NORM-B');
        $user = $this->user($org);
        $role = $this->role($org, 'norm-reader');
        app(TenantContext::class)->runAs($org->id, fn () => $user->assignRole($role));
        RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $org->id, 'permission' => Permission::NormsView->value]);
        $norm = Norm::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'code' => 'A', 'name' => 'Local', 'status' => true]);
        $foreign = Norm::create(['id' => (string) Str::uuid7(), 'organization_id' => $other->id, 'code' => 'B', 'name' => 'Ajena', 'status' => true]);
        $requirement = NormRequirement::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'norm_id' => $norm->id, 'code' => 'R', 'description' => 'Local']);
        $foreignRequirement = NormRequirement::create(['id' => (string) Str::uuid7(), 'organization_id' => $other->id, 'norm_id' => $foreign->id, 'code' => 'R', 'description' => 'Ajeno']);
        $this->actingAsApiInOrganization($user, $org);
        $this->getJson('/api/norms?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $norm->id);
        $this->getJson('/api/norms?per_page=0')->assertUnprocessable();
        $this->getJson('/api/norms/'.$norm->id)->assertOk();
        $this->getJson('/api/norms/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/norms/'.$foreign->id.'/requirements?per_page=0')->assertNotFound();
        $this->getJson('/api/norms/'.$norm->id.'/requirements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $requirement->id);
        $this->getJson('/api/requirements/'.$requirement->id)->assertOk();
        $this->getJson('/api/requirements/'.$foreignRequirement->id)->assertNotFound();
    }
}
