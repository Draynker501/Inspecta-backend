<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ProfilePasswordTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const URL = '/api/auth/password';

    private const CURRENT_PASSWORD = 'InspectaActual2026';

    private const NEW_PASSWORD = 'InspectaNueva2026';

    private Organization $organization;

    private User $actor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->clientOrganization(
            'PROFILE-PASSWORD'
        );

        $this->actor = $this->user(
            $this->organization
        );

        $this->actor->password = self::CURRENT_PASSWORD;
        $this->actor->setRememberToken('remember-test-token');
        $this->actor->save();

        $this->actor->refresh();

        $this->token = $this->actor
            ->createToken('profile-password-test')
            ->plainTextToken;
    }

    /*
    |--------------------------------------------------------------------------
    | Métodos auxiliares
    |--------------------------------------------------------------------------
    */

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'current_password' => self::CURRENT_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides);
    }

    private function changePassword(array $overrides = [])
    {
        return $this->withToken($this->token)
            ->putJson(
                self::URL,
                $this->payload($overrides)
            );
    }

    private function assertPasswordUnchanged(
        string $originalHash
    ): void {
        $this->assertSame(
            $originalHash,
            $this->actor->fresh()->password
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Actualización exitosa de contraseña
    |--------------------------------------------------------------------------
    */

    public function test_user_can_change_own_password(): void
    {
        $originalHash = $this->actor->password;

        $this->changePassword()
            ->assertOk()
            ->assertExactJson([
                'message' => 'Contraseña actualizada correctamente.',
            ]);

        $this->actor->refresh();

        $this->assertNotSame(
            $originalHash,
            $this->actor->password
        );

        $this->assertTrue(
            Hash::check(
                self::NEW_PASSWORD,
                $this->actor->password
            )
        );

        $this->assertFalse(
            Hash::check(
                self::CURRENT_PASSWORD,
                $this->actor->password
            )
        );
    }

    public function test_new_password_is_not_stored_as_plain_text(): void
    {
        $this->changePassword()
            ->assertOk();

        $this->assertNotSame(
            self::NEW_PASSWORD,
            $this->actor->fresh()->password
        );

        $this->assertTrue(
            Hash::check(
                self::NEW_PASSWORD,
                $this->actor->fresh()->password
            )
        );
    }

    public function test_password_change_preserves_personal_information(): void
    {
        $originalName = $this->actor->name;
        $originalEmail = $this->actor->email;
        $originalOrganization = $this->actor->organization_id;
        $originalStatus = $this->actor->status;
        $originalVerification = $this->actor->email_verified_at;

        $this->changePassword()
            ->assertOk();

        $this->actor->refresh();

        $this->assertSame(
            $originalName,
            $this->actor->name
        );

        $this->assertSame(
            $originalEmail,
            $this->actor->email
        );

        $this->assertSame(
            $originalOrganization,
            $this->actor->organization_id
        );

        $this->assertEquals(
            $originalStatus,
            $this->actor->status
        );

        $this->assertEquals(
            $originalVerification,
            $this->actor->email_verified_at
        );
    }

    public function test_password_change_invalidates_remember_token(): void
    {
        $this->assertSame(
            'remember-test-token',
            $this->actor->getRememberToken()
        );

        $this->changePassword()
            ->assertOk();

        $user = $this->actor->fresh();

        $this->assertNull(
            $user->getRawOriginal('remember_token')
        );

        $this->assertEmpty(
            $user->getRememberToken()
        );
    }

    public function test_user_can_login_with_new_password(): void
    {
        $this->changePassword()
            ->assertOk();

        $this->flushHeaders();

        $this->postJson('/api/auth/login', [
            'email' => $this->actor->email,
            'password' => self::NEW_PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.user.id',
                $this->actor->id
            )
            ->assertJsonStructure([
                'data' => [
                    'user',
                    'token',
                ],
            ]);
    }

    public function test_old_password_cannot_be_used_to_login(): void
    {
        $this->changePassword()
            ->assertOk();

        $this->flushHeaders();

        $this->postJson('/api/auth/login', [
            'email' => $this->actor->email,
            'password' => self::CURRENT_PASSWORD,
        ])
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Autenticación y contexto organizacional
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_user_cannot_change_password(): void
    {
        $originalHash = $this->actor->password;

        $this->putJson(
            self::URL,
            $this->payload()
        )->assertUnauthorized();

        $this->assertPasswordUnchanged(
            $originalHash
        );
    }

    public function test_invalid_token_cannot_change_password(): void
    {
        $originalHash = $this->actor->password;

        $this->withToken('invalid-token')
            ->putJson(
                self::URL,
                $this->payload()
            )
            ->assertUnauthorized();

        $this->assertPasswordUnchanged(
            $originalHash
        );
    }

    public function test_inactive_user_cannot_change_password(): void
    {
        $originalHash = $this->actor->password;

        $this->actor->status = false;
        $this->actor->save();

        $this->changePassword()
            ->assertUnauthorized();

        $this->assertPasswordUnchanged(
            $originalHash
        );
    }

    public function test_expired_token_cannot_change_password(): void
    {
        $originalHash = $this->actor->password;

        $token = PersonalAccessToken::findToken(
            $this->token
        );

        $token->expires_at = now()->subMinute();
        $token->save();

        $this->changePassword()
            ->assertUnauthorized();

        $this->assertPasswordUnchanged(
            $originalHash
        );
    }

    public function test_revoked_token_cannot_change_password(): void
    {
        $originalHash = $this->actor->password;

        PersonalAccessToken::findToken(
            $this->token
        )->delete();

        $this->changePassword()
            ->assertUnauthorized();

        $this->assertPasswordUnchanged(
            $originalHash
        );
    }

    public function test_password_change_does_not_require_active_membership(): void
    {
        $this->actor->memberships()->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->changePassword()
            ->assertOk();

        $this->assertTrue(
            Hash::check(
                self::NEW_PASSWORD,
                $this->actor->fresh()->password
            )
        );
    }

    public function test_organization_header_does_not_change_password_target(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PASSWORD-OTHER-ORG'
        );

        $otherUser = $this->user(
            $otherOrganization
        );

        $originalOtherHash = $otherUser->password;

        $this->withHeader(
            'X-Organization-Id',
            $otherOrganization->id
        );

        $this->changePassword()
            ->assertOk();

        $this->assertTrue(
            Hash::check(
                self::NEW_PASSWORD,
                $this->actor->fresh()->password
            )
        );

        $this->assertSame(
            $originalOtherHash,
            $otherUser->fresh()->password
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validación de contraseña actual
    |--------------------------------------------------------------------------
    */

    public function test_incorrect_current_password_is_rejected(): void
    {
        $originalHash = $this->actor->password;

        $this->changePassword([
            'current_password' => 'Incorrecta2026',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'current_password'
            );

        $this->assertPasswordUnchanged(
            $originalHash
        );
    }

    public function test_current_password_is_required(): void
    {
        $payload = $this->payload();

        unset($payload['current_password']);

        $this->withToken($this->token)
            ->putJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'current_password'
            );
    }

    public function test_null_current_password_is_rejected(): void
    {
        $this->changePassword([
            'current_password' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'current_password'
            );
    }

    public function test_non_string_current_password_is_rejected(): void
    {
        $this->changePassword([
            'current_password' => 123456,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'current_password'
            );
    }

    public function test_excessively_long_current_password_is_rejected(): void
    {
        $this->changePassword([
            'current_password' => str_repeat('A', 1025),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'current_password'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Política de seguridad de la nueva contraseña
    |--------------------------------------------------------------------------
    */

    public function test_new_password_is_required(): void
    {
        $payload = $this->payload();

        unset($payload['password']);

        $this->withToken($this->token)
            ->putJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_null_new_password_is_rejected(): void
    {
        $this->changePassword([
            'password' => null,
            'password_confirmation' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_non_string_new_password_is_rejected(): void
    {
        $this->changePassword([
            'password' => 123456789012,
            'password_confirmation' => 123456789012,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_password_shorter_than_12_characters_is_rejected(): void
    {
        $password = 'Abcdef12345';

        $this->changePassword([
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_password_with_exactly_12_characters_is_accepted(): void
    {
        $password = 'Abcdef123456';

        $this->changePassword([
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk();

        $this->assertTrue(
            Hash::check(
                $password,
                $this->actor->fresh()->password
            )
        );
    }

    public function test_password_without_uppercase_is_rejected(): void
    {
        $password = 'inspectanueva2026';

        $this->changePassword([
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_password_without_lowercase_is_rejected(): void
    {
        $password = 'INSPECTANUEVA2026';

        $this->changePassword([
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_password_without_numbers_is_rejected(): void
    {
        $password = 'InspectaNuevaPassword';

        $this->changePassword([
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_password_longer_than_72_bytes_is_rejected(): void
    {
        $password = 'Aa1'.str_repeat('a', 70);

        $this->assertSame(
            73,
            strlen($password)
        );

        $this->changePassword([
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_password_with_exactly_72_bytes_is_accepted(): void
    {
        $password = 'Aa1'.str_repeat('a', 69);

        $this->assertSame(
            72,
            strlen($password)
        );

        $this->changePassword([
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk();

        $this->assertTrue(
            Hash::check(
                $password,
                $this->actor->fresh()->password
            )
        );
    }

    public function test_multibyte_password_exceeding_72_bytes_is_rejected(): void
    {
        $password = 'Aa1'.str_repeat('á', 35);

        $this->assertGreaterThan(
            72,
            strlen($password)
        );

        $this->changePassword([
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_password_containing_null_byte_is_rejected(): void
    {
        $password = "InspectaNueva2026\0";

        $this->changePassword([
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_current_password_cannot_be_reused(): void
    {
        $originalHash = $this->actor->password;

        $this->changePassword([
            'password' => self::CURRENT_PASSWORD,
            'password_confirmation' => self::CURRENT_PASSWORD,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertPasswordUnchanged(
            $originalHash
        );
    }

    public function test_password_confirmation_is_required(): void
    {
        $payload = $this->payload();

        unset($payload['password_confirmation']);

        $this->withToken($this->token)
            ->putJson(self::URL, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'password_confirmation'
            );
    }

    public function test_incorrect_password_confirmation_is_rejected(): void
    {
        $this->changePassword([
            'password_confirmation' => 'InspectaDistinta2026',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_non_string_password_confirmation_is_rejected(): void
    {
        $this->changePassword([
            'password_confirmation' => 123456,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'password_confirmation'
            );
    }

    public function test_password_spaces_are_preserved(): void
    {
        $password = ' InspectaNueva2026 ';

        $this->changePassword([
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk();

        $hash = $this->actor->fresh()->password;

        $this->assertTrue(
            Hash::check($password, $hash)
        );

        $this->assertFalse(
            Hash::check(trim($password), $hash)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Gestión y revocación de sesiones
    |--------------------------------------------------------------------------
    */

    public function test_current_token_remains_valid_after_password_change(): void
    {
        $this->changePassword()
            ->assertOk();

        $this->assertNotNull(
            PersonalAccessToken::findToken(
                $this->token
            )
        );

        $this->withToken($this->token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $this->actor->id
            );
    }

    public function test_other_tokens_are_revoked_after_password_change(): void
    {
        $secondToken = $this->actor
            ->createToken('second-session')
            ->plainTextToken;

        $thirdToken = $this->actor
            ->createToken('third-session')
            ->plainTextToken;

        $this->assertSame(
            3,
            $this->actor->tokens()->count()
        );

        $this->changePassword()
            ->assertOk();

        $this->assertSame(
            1,
            $this->actor->tokens()->count()
        );

        $this->assertNotNull(
            PersonalAccessToken::findToken(
                $this->token
            )
        );

        $this->assertNull(
            PersonalAccessToken::findToken(
                $secondToken
            )
        );

        $this->assertNull(
            PersonalAccessToken::findToken(
                $thirdToken
            )
        );
    }

    public function test_revoked_session_cannot_access_api_after_password_change(): void
    {
        $otherToken = $this->actor
            ->createToken('other-session')
            ->plainTextToken;

        $this->withToken($otherToken)
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->changePassword()
            ->assertOk();

        $this->withToken($otherToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        $this->withToken($this->token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_other_users_tokens_are_not_revoked(): void
    {
        $otherUser = $this->user(
            $this->organization
        );

        $otherToken = $otherUser
            ->createToken('other-user-session')
            ->plainTextToken;

        $originalOtherHash = $otherUser->password;

        $this->changePassword()
            ->assertOk();

        $this->assertNotNull(
            PersonalAccessToken::findToken(
                $otherToken
            )
        );

        $this->assertSame(
            $originalOtherHash,
            $otherUser->fresh()->password
        );

        $this->withToken($otherToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $otherUser->id
            );
    }

    public function test_failed_password_change_preserves_all_tokens(): void
    {
        $originalHash = $this->actor->password;

        $otherToken = $this->actor
            ->createToken('other-session')
            ->plainTextToken;

        $this->changePassword([
            'current_password' => 'Incorrecta2026',
        ])->assertUnprocessable();

        $this->assertPasswordUnchanged(
            $originalHash
        );

        $this->assertSame(
            2,
            $this->actor->tokens()->count()
        );

        $this->assertNotNull(
            PersonalAccessToken::findToken(
                $this->token
            )
        );

        $this->assertNotNull(
            PersonalAccessToken::findToken(
                $otherToken
            )
        );
    }

    public function test_validation_failure_preserves_all_tokens(): void
    {
        $originalHash = $this->actor->password;

        $otherToken = $this->actor
            ->createToken('other-session')
            ->plainTextToken;

        $this->changePassword([
            'password' => 'Abc123',
            'password_confirmation' => 'Abc123',
        ])->assertUnprocessable();

        $this->assertPasswordUnchanged(
            $originalHash
        );

        $this->assertSame(
            2,
            $this->actor->tokens()->count()
        );

        $this->assertNotNull(
            PersonalAccessToken::findToken(
                $otherToken
            )
        );
    }

    public function test_failed_password_change_preserves_remember_token(): void
    {
        $originalRememberToken =
            $this->actor->getRememberToken();

        $this->changePassword([
            'current_password' => 'Incorrecta2026',
        ])->assertUnprocessable();

        $this->assertSame(
            $originalRememberToken,
            $this->actor->fresh()->getRememberToken()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Protección contra modificaciones no autorizadas
    |--------------------------------------------------------------------------
    */

    public function test_user_cannot_change_another_users_password(): void
    {
        $otherUser = $this->user(
            $this->organization
        );

        $originalHash = $this->actor->password;
        $originalOtherHash = $otherUser->password;

        $this->changePassword([
            'user_id' => $otherUser->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertPasswordUnchanged(
            $originalHash
        );

        $this->assertSame(
            $originalOtherHash,
            $otherUser->fresh()->password
        );
    }

    public function test_user_cannot_modify_account_status(): void
    {
        $this->changePassword([
            'status' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertTrue(
            (bool) $this->actor->fresh()->status
        );
    }

    public function test_user_cannot_modify_roles_or_permissions(): void
    {
        $this->changePassword([
            'roles' => ['Administrador'],
            'permissions' => ['users.manage'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'roles',
                'permissions',
            ]);
    }

    public function test_user_cannot_modify_organization_id(): void
    {
        $originalOrganization =
            $this->actor->organization_id;

        $this->changePassword([
            'organization_id' => 'unauthorized-organization',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'organization_id'
            );

        $this->assertSame(
            $originalOrganization,
            $this->actor->fresh()->organization_id
        );
    }

    public function test_unknown_fields_are_rejected(): void
    {
        $this->changePassword([
            'unknown_field' => 'value',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'unknown_field'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Estructura de solicitudes
    |--------------------------------------------------------------------------
    */

    public function test_empty_json_object_is_rejected(): void
    {
        $this->call(
            'PUT',
            self::URL,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
            '{}'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'current_password',
                'password',
                'password_confirmation',
            ]);
    }

    public function test_malformed_json_is_rejected(): void
    {
        $this->call(
            'PUT',
            self::URL,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
            '{"password":'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_json_array_is_rejected(): void
    {
        $this->withToken($this->token)
            ->putJson(self::URL, [
                self::CURRENT_PASSWORD,
                self::NEW_PASSWORD,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_query_parameters_are_rejected(): void
    {
        $this->withToken($this->token)
            ->putJson(
                self::URL.'?user_id=other-user',
                $this->payload()
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');
    }

    public function test_non_json_content_type_is_rejected(): void
    {
        $this->withToken($this->token)
            ->withHeader(
                'Accept',
                'application/json'
            )
            ->put(
                self::URL,
                $this->payload()
            )
            ->assertStatus(415);
    }

    /*
    |--------------------------------------------------------------------------
    | Respuestas y registro de eventos
    |--------------------------------------------------------------------------
    */

    public function test_response_does_not_expose_sensitive_information(): void
    {
        $this->changePassword()
            ->assertOk()
            ->assertExactJson([
                'message' => 'Contraseña actualizada correctamente.',
            ]);
    }

    public function test_successful_password_change_logs_only_safe_metadata(): void
    {
        $this->actor->createToken('second-session');
        $this->actor->createToken('third-session');

        Log::spy();

        $this->changePassword()
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->with(
                'user.password_updated',
                [
                    'user_id' => $this->actor->id,
                    'revoked_tokens_count' => 2,
                ]
            );
    }

    public function test_failed_password_change_does_not_generate_success_event(): void
    {
        Log::spy();

        Log::shouldReceive('info')
            ->withArgs(
                fn ($message, ...$arguments) => $message === 'user.password_updated'
            )
            ->never();

        $this->changePassword([
            'current_password' => 'Incorrecta2026',
        ])->assertUnprocessable();
    }

    public function test_validation_failure_does_not_generate_success_event(): void
    {
        Log::spy();

        Log::shouldReceive('info')
            ->withArgs(
                fn ($message, ...$arguments) => $message === 'user.password_updated'
            )
            ->never();

        $this->changePassword([
            'password' => 'Abc123',
            'password_confirmation' => 'Abc123',
        ])->assertUnprocessable();
    }

    /*
    |--------------------------------------------------------------------------
    | Limitación de solicitudes
    |--------------------------------------------------------------------------
    */

    public function test_password_change_attempts_are_rate_limited(): void
    {
        $originalHash = $this->actor->password;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->changePassword([
                'current_password' => 'Incorrecta2026',
            ])
                ->assertUnprocessable()
                ->assertHeader('X-RateLimit-Limit', '5')
                ->assertHeader(
                    'X-RateLimit-Remaining',
                    (string) (5 - $attempt)
                );
        }

        $this->changePassword([
            'current_password' => 'Incorrecta2026',
        ])
            ->assertStatus(429)
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertPasswordUnchanged(
            $originalHash
        );
    }

    public function test_password_rate_limit_is_shared_between_user_tokens(): void
    {
        $secondToken = $this->actor
            ->createToken('second-session')
            ->plainTextToken;

        $originalHash = $this->actor->password;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->changePassword([
                'current_password' => 'Incorrecta2026',
            ])->assertUnprocessable();
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withToken($secondToken)
                ->putJson(
                    self::URL,
                    $this->payload([
                        'current_password' => 'Incorrecta2026',
                    ])
                )
                ->assertUnprocessable();
        }

        $this->withToken($secondToken)
            ->putJson(
                self::URL,
                $this->payload()
            )
            ->assertStatus(429);

        $this->changePassword()
            ->assertStatus(429);

        $this->assertPasswordUnchanged(
            $originalHash
        );
    }

    public function test_password_rate_limit_is_isolated_between_users(): void
    {
        $otherUser = $this->user(
            $this->organization
        );

        $otherUser->password = self::CURRENT_PASSWORD;
        $otherUser->save();

        $otherToken = $otherUser
            ->createToken('other-user-session')
            ->plainTextToken;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->changePassword([
                'current_password' => 'Incorrecta2026',
            ])->assertUnprocessable();
        }

        $this->changePassword()
            ->assertStatus(429);

        $this->withToken($otherToken)
            ->putJson(
                self::URL,
                $this->payload()
            )
            ->assertOk();

        $this->assertTrue(
            Hash::check(
                self::NEW_PASSWORD,
                $otherUser->fresh()->password
            )
        );

        $this->assertTrue(
            Hash::check(
                self::CURRENT_PASSWORD,
                $this->actor->fresh()->password
            )
        );
    }

    public function test_password_rate_limit_expires_after_one_minute(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->changePassword([
                'current_password' => 'Incorrecta2026',
            ])->assertUnprocessable();
        }

        $this->changePassword()
            ->assertStatus(429);

        $this->travel(61)->seconds();

        $this->changePassword()
            ->assertOk();

        $this->assertTrue(
            Hash::check(
                self::NEW_PASSWORD,
                $this->actor->fresh()->password
            )
        );

        $this->travelBack();
    }
}
