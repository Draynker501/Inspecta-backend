<?php

namespace Tests\Feature\Tenancy;

use App\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class TenantErrorCodesTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private $orgA;

    private $orgB;

    private $operatorOrg;

    private $user;

    private $revokedMembershipOrg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = $this->organization('ERR-A');
        $this->orgB = $this->organization('ERR-B');
        $this->operatorOrg = $this->operatorOrganization();
        $this->revokedMembershipOrg = $this->organization('ERR-REV');

        $this->user = $this->user($this->orgA);

        // Membresía revocada en ERR-REV.
        OrganizationMembership::firstOrCreate(
            ['user_id' => $this->user->id, 'organization_id' => $this->revokedMembershipOrg->id],
            ['id' => (string) Str::uuid7(), 'status' => OrganizationMembership::STATUS_REVOKED, 'revoked_at' => now()]
        );

        Route::prefix('api/err-probe')->middleware('api.tenant')->group(function (): void {
            Route::get('ping', function () {
                return response()->json(['pong' => true]);
            });
        });
    }

    private function token(): string
    {
        return $this->user->createToken('t')->plainTextToken;
    }

    private function probe(string $orgId, string $token): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $orgId)
            ->getJson('/api/err-probe/ping');
    }

    public function test_cabecera_ausente_organization_required(): void
    {
        $token = $this->token();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/err-probe/ping');
        $response->assertStatus(400)
            ->assertJsonPath('code', 'organization_required');
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_uuid_invalido_organization_invalid(): void
    {
        $token = $this->token();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', 'no-es-uuid')
            ->getJson('/api/err-probe/ping');
        $response->assertStatus(400)
            ->assertJsonPath('code', 'organization_invalid');
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_organizacion_inexistente_t27_organization_access_denied(): void
    {
        $token = $this->token();
        $response = $this->probe((string) Str::uuid7(), $token);
        $response->assertStatus(403)
            ->assertJsonPath('code', 'organization_access_denied');
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_organizacion_eliminada_t08_organization_access_denied(): void
    {
        $org = $this->organization('ERR-DEL');
        $org->delete();
        $token = $this->token();
        $response = $this->probe($org->id, $token);
        $response->assertStatus(403)
            ->assertJsonPath('code', 'organization_access_denied');
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_organizacion_inactiva_t07_organization_access_denied(): void
    {
        $org = $this->organization('ERR-INACT');
        $org->update(['status' => false]);
        $token = $this->token();
        $response = $this->probe($org->id, $token);
        $response->assertStatus(403)
            ->assertJsonPath('code', 'organization_access_denied');
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_usuario_sin_membresia_t05_organization_access_denied(): void
    {
        $token = $this->token();
        $response = $this->probe($this->orgB->id, $token);
        $response->assertStatus(403)
            ->assertJsonPath('code', 'organization_access_denied');
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_membresia_revocada_organization_access_denied(): void
    {
        $token = $this->token();
        $response = $this->probe($this->revokedMembershipOrg->id, $token);
        $response->assertStatus(403)
            ->assertJsonPath('code', 'organization_access_denied');
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_operadora_t41_organization_not_tenant(): void
    {
        $token = $this->token();
        $response = $this->probe($this->operatorOrg->id, $token);
        $response->assertStatus(403)
            ->assertJsonPath('code', 'organization_not_tenant');
        $this->assertNotEmpty($response->json('message'));
    }
}
