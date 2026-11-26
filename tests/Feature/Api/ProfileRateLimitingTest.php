<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ProfileRateLimitingTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const PROFILE_URL = '/api/auth/me';

    private const PASSWORD_URL = '/api/auth/password';

    private const CURRENT_PASSWORD = 'InspectaActual2026';

    private const NEW_PASSWORD = 'InspectaNueva2026';

    private Organization $organization;

    private User $actor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->clientOrganization(
            'PROFILE-RATE-LIMIT'
        );

        $this->actor = $this->user(
            $this->organization
        );

        $this->actor->password = Hash::make(
            self::CURRENT_PASSWORD
        );

        $this->actor->save();

        $this->actor->refresh();

        $this->token = $this->actor
            ->createToken('profile-rate-limit-test')
            ->plainTextToken;
    }

    /*
    |--------------------------------------------------------------------------
    | Métodos auxiliares
    |--------------------------------------------------------------------------
    */

    private function updateProfile(
        string $name,
        ?string $token = null
    ): TestResponse {
        return $this->withToken(
            $token ?? $this->token
        )->patchJson(
            self::PROFILE_URL,
            [
                'name' => $name,
            ]
        );
    }

    private function changePassword(
        ?string $token = null,
        string $currentPassword = self::CURRENT_PASSWORD
    ): TestResponse {
        return $this->withToken(
            $token ?? $this->token
        )->putJson(
            self::PASSWORD_URL,
            [
                'current_password' => $currentPassword,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]
        );
    }

    private function failedPasswordAttempt(
        ?string $token = null
    ): TestResponse {
        return $this->changePassword(
            $token,
            'Incorrecta2026'
        );
    }

    private function exhaustProfileLimit(
        ?string $token = null
    ): void {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->updateProfile(
                'Usuario intento '.$attempt,
                $token
            )->assertOk();
        }
    }

    private function exhaustPasswordLimit(
        ?string $token = null
    ): void {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->failedPasswordAttempt(
                $token
            )->assertUnprocessable();
        }
    }

    private function assertFirstProfileRequest(
        ?string $token = null
    ): void {
        $this->updateProfile(
            'Usuario actualizado',
            $token
        )
            ->assertOk()
            ->assertHeader(
                'X-RateLimit-Limit',
                '10'
            )
            ->assertHeader(
                'X-RateLimit-Remaining',
                '9'
            );
    }

    private function assertFirstPasswordRequest(
        ?string $token = null
    ): void {
        $this->failedPasswordAttempt(
            $token
        )
            ->assertUnprocessable()
            ->assertHeader(
                'X-RateLimit-Limit',
                '5'
            )
            ->assertHeader(
                'X-RateLimit-Remaining',
                '4'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Rate limiting de actualización de perfil
    |--------------------------------------------------------------------------
    */

    public function test_profile_update_allows_ten_requests_per_minute(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->updateProfile(
                'Usuario intento '.$attempt
            )
                ->assertOk()
                ->assertHeader(
                    'X-RateLimit-Limit',
                    '10'
                )
                ->assertHeader(
                    'X-RateLimit-Remaining',
                    (string) (10 - $attempt)
                );
        }

        $this->assertSame(
            'Usuario intento 10',
            $this->actor->fresh()->name
        );
    }

    public function test_eleventh_profile_update_is_rejected(): void
    {
        $this->exhaustProfileLimit();

        $this->updateProfile(
            'Cambio no permitido'
        )
            ->assertStatus(429)
            ->assertHeader(
                'X-RateLimit-Remaining',
                '0'
            )
            ->assertHeader('Retry-After');

        $this->assertSame(
            'Usuario intento 10',
            $this->actor->fresh()->name
        );
    }

    public function test_profile_update_limit_is_shared_between_tokens(): void
    {
        $secondToken = $this->actor
            ->createToken('second-session')
            ->plainTextToken;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->updateProfile(
                'Primera sesion '.$attempt
            )
                ->assertOk()
                ->assertHeader(
                    'X-RateLimit-Remaining',
                    (string) (10 - $attempt)
                );
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->updateProfile(
                'Segunda sesion '.$attempt,
                $secondToken
            )
                ->assertOk()
                ->assertHeader(
                    'X-RateLimit-Remaining',
                    (string) (5 - $attempt)
                );
        }

        $this->updateProfile(
            'Cambio bloqueado desde primera sesion'
        )->assertStatus(429);

        $this->updateProfile(
            'Cambio bloqueado desde segunda sesion',
            $secondToken
        )->assertStatus(429);

        $this->assertSame(
            'Segunda sesion 5',
            $this->actor->fresh()->name
        );
    }

    public function test_profile_update_limit_is_isolated_between_users(): void
    {
        $otherUser = $this->user(
            $this->organization
        );

        $otherToken = $otherUser
            ->createToken('other-user-session')
            ->plainTextToken;

        $this->exhaustProfileLimit();

        $this->updateProfile(
            'Cambio bloqueado'
        )->assertStatus(429);

        $this->assertFirstProfileRequest(
            $otherToken
        );

        $this->assertSame(
            'Usuario actualizado',
            $otherUser->fresh()->name
        );

        $this->assertSame(
            'Usuario intento 10',
            $this->actor->fresh()->name
        );
    }

    public function test_failed_profile_validation_consumes_quota(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withToken($this->token)
                ->patchJson(
                    self::PROFILE_URL,
                    [
                        'name' => '',
                    ]
                )
                ->assertUnprocessable()
                ->assertJsonValidationErrors('name')
                ->assertHeader(
                    'X-RateLimit-Remaining',
                    (string) (10 - $attempt)
                );
        }

        $this->updateProfile(
            'Nombre valido'
        )->assertStatus(429);
    }

    public function test_profile_update_limit_recovers_after_one_minute(): void
    {
        $this->exhaustProfileLimit();

        $this->updateProfile(
            'Cambio bloqueado'
        )->assertStatus(429);

        $this->travel(61)->seconds();

        try {
            $this->assertFirstProfileRequest();
        } finally {
            $this->travelBack();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Independencia entre limitadores
    |--------------------------------------------------------------------------
    */

    public function test_profile_limit_does_not_block_password_changes(): void
    {
        $this->exhaustProfileLimit();

        $this->updateProfile(
            'Cambio bloqueado'
        )->assertStatus(429);

        $this->changePassword()
            ->assertOk()
            ->assertHeader(
                'X-RateLimit-Limit',
                '5'
            )
            ->assertHeader(
                'X-RateLimit-Remaining',
                '4'
            );

        $this->assertTrue(
            Hash::check(
                self::NEW_PASSWORD,
                $this->actor->fresh()->password
            )
        );
    }

    public function test_password_limit_does_not_block_profile_updates(): void
    {
        $this->exhaustPasswordLimit();

        $this->changePassword()
            ->assertStatus(429);

        $this->assertFirstProfileRequest();

        $this->assertTrue(
            Hash::check(
                self::CURRENT_PASSWORD,
                $this->actor->fresh()->password
            )
        );
    }

    public function test_profile_reads_do_not_consume_write_quota(): void
    {
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $this->withToken($this->token)
                ->getJson(self::PROFILE_URL)
                ->assertOk();
        }

        $this->assertFirstProfileRequest();

        $this->assertFirstPasswordRequest();
    }

    public function test_exhausted_write_limits_do_not_block_profile_reads(): void
    {
        $this->exhaustProfileLimit();

        $this->exhaustPasswordLimit();

        $this->updateProfile(
            'Cambio bloqueado'
        )->assertStatus(429);

        $this->changePassword()
            ->assertStatus(429);

        $this->withToken($this->token)
            ->getJson(self::PROFILE_URL)
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $this->actor->id
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Tokens expirados
    |--------------------------------------------------------------------------
    */

    public function test_explicitly_expired_token_does_not_consume_profile_quota(): void
    {
        $expiredToken = $this->actor
            ->createToken(
                'expired-session',
                ['*'],
                now()->subMinute()
            )
            ->plainTextToken;

        $this->updateProfile(
            'Cambio no autorizado',
            $expiredToken
        )->assertUnauthorized();

        $this->assertFirstProfileRequest();
    }

    public function test_explicitly_expired_token_does_not_consume_password_quota(): void
    {
        $expiredToken = $this->actor
            ->createToken(
                'expired-session',
                ['*'],
                now()->subMinute()
            )
            ->plainTextToken;

        $this->changePassword(
            $expiredToken
        )->assertUnauthorized();

        $this->assertFirstPasswordRequest();
    }

    public function test_globally_expired_token_does_not_consume_profile_quota(): void
    {
        config([
            'sanctum.expiration' => 60,
        ]);

        $expiredToken = $this->actor
            ->createToken('globally-expired-session')
            ->plainTextToken;

        $accessToken = PersonalAccessToken::findToken(
            $expiredToken
        );

        $this->assertNotNull($accessToken);

        $accessToken->created_at = now()->subMinutes(61);

        $accessToken->save();

        $this->updateProfile(
            'Cambio no autorizado',
            $expiredToken
        )->assertUnauthorized();

        $this->assertFirstProfileRequest();
    }

    public function test_globally_expired_token_does_not_consume_password_quota(): void
    {
        config([
            'sanctum.expiration' => 60,
        ]);

        $expiredToken = $this->actor
            ->createToken('globally-expired-session')
            ->plainTextToken;

        $accessToken = PersonalAccessToken::findToken(
            $expiredToken
        );

        $this->assertNotNull($accessToken);

        $accessToken->created_at = now()->subMinutes(61);

        $accessToken->save();

        $this->changePassword(
            $expiredToken
        )->assertUnauthorized();

        $this->assertFirstPasswordRequest();
    }

    /*
    |--------------------------------------------------------------------------
    | Tokens revocados e inválidos
    |--------------------------------------------------------------------------
    */

    public function test_revoked_token_does_not_consume_profile_quota(): void
    {
        $revokedToken = $this->actor
            ->createToken('revoked-session')
            ->plainTextToken;

        $accessToken = PersonalAccessToken::findToken(
            $revokedToken
        );

        $this->assertNotNull($accessToken);

        $accessToken->delete();

        $this->updateProfile(
            'Cambio no autorizado',
            $revokedToken
        )->assertUnauthorized();

        $this->assertFirstProfileRequest();
    }

    public function test_revoked_token_does_not_consume_password_quota(): void
    {
        $revokedToken = $this->actor
            ->createToken('revoked-session')
            ->plainTextToken;

        $accessToken = PersonalAccessToken::findToken(
            $revokedToken
        );

        $this->assertNotNull($accessToken);

        $accessToken->delete();

        $this->changePassword(
            $revokedToken
        )->assertUnauthorized();

        $this->assertFirstPasswordRequest();
    }

    public function test_invalid_token_does_not_consume_profile_quota(): void
    {
        $this->updateProfile(
            'Cambio no autorizado',
            'invalid-token'
        )->assertUnauthorized();

        $this->assertFirstProfileRequest();
    }

    public function test_invalid_token_does_not_consume_password_quota(): void
    {
        $this->changePassword(
            'invalid-token'
        )->assertUnauthorized();

        $this->assertFirstPasswordRequest();
    }

    /*
    |--------------------------------------------------------------------------
    | Usuarios desactivados
    |--------------------------------------------------------------------------
    */

    public function test_inactive_user_does_not_consume_personal_profile_quota(): void
    {
        $this->actor->status = false;

        $this->actor->save();

        $this->updateProfile(
            'Cambio no autorizado'
        )->assertUnauthorized();

        $this->actor->status = true;

        $this->actor->save();

        $this->assertFirstProfileRequest();
    }

    public function test_inactive_user_does_not_consume_personal_password_quota(): void
    {
        $this->actor->status = false;

        $this->actor->save();

        $this->changePassword()
            ->assertUnauthorized();

        $this->actor->status = true;

        $this->actor->save();

        $this->assertFirstPasswordRequest();
    }

    /*
    |--------------------------------------------------------------------------
    | Independencia del contexto organizacional
    |--------------------------------------------------------------------------
    */

    public function test_profile_quota_is_independent_of_organization_header(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-RATE-OTHER'
        );

        $this->withHeader(
            'X-Organization-Id',
            $this->organization->id
        );

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->updateProfile(
                'Organizacion A '.$attempt
            )->assertOk();
        }

        $this->withHeader(
            'X-Organization-Id',
            $otherOrganization->id
        );

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->updateProfile(
                'Organizacion B '.$attempt
            )
                ->assertOk()
                ->assertHeader(
                    'X-RateLimit-Remaining',
                    (string) (5 - $attempt)
                );
        }

        $this->updateProfile(
            'Cambio bloqueado'
        )->assertStatus(429);

        $this->assertSame(
            'Organizacion B 5',
            $this->actor->fresh()->name
        );

        $this->assertSame(
            $this->organization->id,
            $this->actor->fresh()->organization_id
        );
    }

    public function test_password_quota_is_independent_of_organization_header(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PASSWORD-RATE-OTHER'
        );

        $this->withHeader(
            'X-Organization-Id',
            $this->organization->id
        );

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->failedPasswordAttempt()
                ->assertUnprocessable();
        }

        $this->withHeader(
            'X-Organization-Id',
            $otherOrganization->id
        );

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->failedPasswordAttempt()
                ->assertUnprocessable()
                ->assertHeader(
                    'X-RateLimit-Remaining',
                    (string) (2 - $attempt)
                );
        }

        $this->changePassword()
            ->assertStatus(429);

        $this->assertTrue(
            Hash::check(
                self::CURRENT_PASSWORD,
                $this->actor->fresh()->password
            )
        );
    }
}
