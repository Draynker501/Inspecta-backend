<?php

namespace Tests\Feature\Auth;

use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class LoginWithoutMembershipTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_usuario_activo_sin_membresias_puede_iniciar_sesion_y_lista_vacia(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token', fn ($token) => is_string($token) && $token !== '');

        $this->actingAsApi($user)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_usuario_con_todas_las_membresias_revocadas_puede_iniciar_sesion_y_lista_vacia(): void
    {
        $organization = $this->organization('ORG-REVOKED-ALL');
        $user = $this->user($organization);

        OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->update(['status' => OrganizationMembership::STATUS_REVOKED]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token', fn ($token) => is_string($token) && $token !== '');

        $this->actingAsApi($user)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_usuario_con_una_activa_y_una_revocada_solo_lista_la_activa(): void
    {
        $activeOrg = $this->organization('ORG-ACTIVE');
        $revokedOrg = $this->organization('ORG-REVOKED');

        $user = $this->user($activeOrg);

        OrganizationMembership::firstOrCreate(
            ['user_id' => $user->id, 'organization_id' => $revokedOrg->id],
            [
                'id' => (string) Str::uuid7(),
                'status' => OrganizationMembership::STATUS_REVOKED,
                'revoked_at' => now(),
            ]
        );

        $this->actingAsApi($user)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeOrg->id);
    }
}
