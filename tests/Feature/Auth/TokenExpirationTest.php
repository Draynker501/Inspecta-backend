<?php

namespace Tests\Feature\Auth;

use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * Cobertura de la expiración de tokens en EnsureApiAuthenticated.
 *
 * El middleware resuelve el token directamente (no usa el Guard de Sanctum),
 * por lo que debe aplicar la misma política: expires_at explícito o
 * antigüedad mayor a sanctum.expiration.
 */
class TokenExpirationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function issueToken(
        User $user,
        ?Carbon $createdAt = null,
        ?Carbon $expiresAt = null
    ): string {
        $newToken = $user->createToken('tests');

        $attributes = [];

        if ($createdAt !== null) {
            $attributes['created_at'] = $createdAt;
        }

        if ($expiresAt !== null) {
            $attributes['expires_at'] = $expiresAt;
        }

        if ($attributes !== []) {
            $newToken->accessToken->forceFill($attributes)->save();
        }

        return $newToken->plainTextToken;
    }

    public function test_token_vigente_autentica(): void
    {
        config(['sanctum.expiration' => 720]);

        $user = User::factory()->create();
        $token = $this->issueToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_token_de_719_minutos_sigue_vigente(): void
    {
        config(['sanctum.expiration' => 720]);

        $user = User::factory()->create();
        $token = $this->issueToken($user, now()->subMinutes(719));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_token_que_alcanza_720_minutos_devuelve_401(): void
    {
        config(['sanctum.expiration' => 720]);

        $user = User::factory()->create();
        $token = $this->issueToken($user, now()->subMinutes(720));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_token_vencido_por_antiguedad_devuelve_401(): void
    {
        config(['sanctum.expiration' => 720]);

        $user = User::factory()->create();
        $token = $this->issueToken($user, now()->subMinutes(721));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_expires_at_pasado_devuelve_401(): void
    {
        config(['sanctum.expiration' => 720]);

        $user = User::factory()->create();
        $token = $this->issueToken($user, expiresAt: now()->subMinute());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_expires_at_futuro_autentica(): void
    {
        config(['sanctum.expiration' => 720]);

        $user = User::factory()->create();
        $token = $this->issueToken($user, expiresAt: now()->addHour());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_sin_expiracion_configurada_se_conserva_la_politica_actual(): void
    {
        config(['sanctum.expiration' => null]);

        $user = User::factory()->create();
        $token = $this->issueToken($user, now()->subYear());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_expiracion_cero_se_interpreta_como_sin_limite(): void
    {
        config(['sanctum.expiration' => 0]);

        $user = User::factory()->create();
        $token = $this->issueToken($user, now()->subYear());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_logout_revoca_el_token_actual_y_lo_deja_de_aceptar(): void
    {
        config(['sanctum.expiration' => 720]);

        $user = User::factory()->create();
        $token = $this->issueToken($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_usuario_revocado_deja_de_autenticar(): void
    {
        config(['sanctum.expiration' => 720]);

        $user = User::factory()->create();
        $token = $this->issueToken($user);

        $user->forceFill(['status' => false])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_membresia_revocada_no_obtiene_contexto_de_tenant(): void
    {
        config(['sanctum.expiration' => 720]);

        $organization = $this->organization('ORG-EXPIRATION');
        $user = $this->user($organization);
        $token = $this->issueToken($user);

        OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->update([
                'status' => OrganizationMembership::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/auth/capabilities')
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_access_denied');
    }
}
