<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const URL = '/api/auth/me';

    private const PASSWORD = 'InspectaActual2026';

    private Organization $organization;

    private User $actor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->clientOrganization(
            'PROFILE-UPDATE'
        );

        $this->actor = $this->user(
            $this->organization
        );

        $this->actor->password = self::PASSWORD;
        $this->actor->email_verified_at = now();
        $this->actor->save();

        $this->token = $this->actor
            ->createToken('profile-update-test')
            ->plainTextToken;
    }

    /*
    |--------------------------------------------------------------------------
    | Actualizaciones exitosas
    |--------------------------------------------------------------------------
    */

    public function test_user_can_update_own_name_without_organization(): void
    {
        $originalEmail = $this->actor->email;
        $originalHash = $this->actor->password;

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre actualizado',
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $this->actor->id
            )
            ->assertJsonPath(
                'data.name',
                'Nombre actualizado'
            )
            ->assertJsonPath(
                'data.email',
                $originalEmail
            );

        $this->actor->refresh();

        $this->assertSame(
            'Nombre actualizado',
            $this->actor->name
        );

        $this->assertSame(
            $originalEmail,
            $this->actor->email
        );

        $this->assertSame(
            $originalHash,
            $this->actor->password
        );
    }

    public function test_user_can_update_email_with_current_password(): void
    {
        $originalName = $this->actor->name;

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => 'nuevo@inspecta.test',
                'current_password' => self::PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.email',
                'nuevo@inspecta.test'
            );

        $this->actor->refresh();

        $this->assertSame(
            'nuevo@inspecta.test',
            $this->actor->email
        );

        $this->assertSame(
            $originalName,
            $this->actor->name
        );

        $this->assertTrue(
            Hash::check(
                self::PASSWORD,
                $this->actor->password
            )
        );
    }

    public function test_user_can_update_name_and_email_together(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nuevo nombre',
                'email' => 'nuevo@inspecta.test',
                'current_password' => self::PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Nuevo nombre'
            )
            ->assertJsonPath(
                'data.email',
                'nuevo@inspecta.test'
            );

        $this->assertDatabaseHas('users', [
            'id' => $this->actor->id,
            'name' => 'Nuevo nombre',
            'email' => 'nuevo@inspecta.test',
        ]);
    }

    public function test_name_and_email_are_trimmed(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => '  Nombre actualizado  ',
                'email' => '  nuevo@inspecta.test  ',
                'current_password' => self::PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Nombre actualizado'
            )
            ->assertJsonPath(
                'data.email',
                'nuevo@inspecta.test'
            );
    }

    public function test_unchanged_email_does_not_require_password(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => $this->actor->email,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.email',
                $this->actor->email
            );
    }

    public function test_email_change_resets_email_verification(): void
    {
        $this->assertNotNull(
            $this->actor->email_verified_at
        );

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => 'nuevo@inspecta.test',
                'current_password' => self::PASSWORD,
            ])
            ->assertOk();

        $this->assertNull(
            $this->actor->fresh()->email_verified_at
        );
    }

    public function test_name_change_preserves_email_verification(): void
    {
        $originalVerification =
            $this->actor->email_verified_at;

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre actualizado',
            ])
            ->assertOk();

        $this->assertEquals(
            $originalVerification,
            $this->actor->fresh()->email_verified_at
        );
    }

    public function test_current_token_remains_valid_after_profile_update(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre actualizado',
                'email' => 'nuevo@inspecta.test',
                'current_password' => self::PASSWORD,
            ])
            ->assertOk();

        $this->withToken($this->token)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Nombre actualizado'
            )
            ->assertJsonPath(
                'data.email',
                'nuevo@inspecta.test'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Autenticación y autorización
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_user_cannot_update_profile(): void
    {
        $this->patchJson(self::URL, [
            'name' => 'Nombre no autorizado',
        ])->assertUnauthorized();
    }

    public function test_invalid_token_cannot_update_profile(): void
    {
        $this->withToken('invalid-token')
            ->patchJson(self::URL, [
                'name' => 'Nombre no autorizado',
            ])
            ->assertUnauthorized();
    }

    public function test_inactive_user_cannot_update_profile(): void
    {
        $originalName = $this->actor->name;

        $this->actor->status = false;
        $this->actor->save();

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre no autorizado',
            ])
            ->assertUnauthorized();

        $this->assertSame(
            $originalName,
            $this->actor->fresh()->name
        );
    }

    public function test_profile_update_does_not_require_active_membership(): void
    {
        $this->actor->memberships()->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Identidad global actualizada',
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Identidad global actualizada'
            );
    }

    public function test_organization_header_does_not_change_profile_target(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-OTHER'
        );

        $otherUser = $this->user(
            $otherOrganization
        );

        $originalOtherName = $otherUser->name;

        $this->withToken($this->token)
            ->withHeader(
                'X-Organization-Id',
                $otherOrganization->id
            )
            ->patchJson(self::URL, [
                'name' => 'Identidad propia',
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $this->actor->id
            );

        $this->assertSame(
            'Identidad propia',
            $this->actor->fresh()->name
        );

        $this->assertSame(
            $originalOtherName,
            $otherUser->fresh()->name
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validación de nombre
    |--------------------------------------------------------------------------
    */

    public function test_empty_name_is_rejected(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_whitespace_only_name_is_rejected(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => '     ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_null_name_is_rejected(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_non_string_name_is_rejected(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 12345,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_name_longer_than_150_characters_is_rejected(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => str_repeat('A', 151),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_name_with_exactly_150_characters_is_accepted(): void
    {
        $name = str_repeat('A', 150);

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => $name,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                $name
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Validación de correo electrónico
    |--------------------------------------------------------------------------
    */

    public function test_email_change_requires_current_password(): void
    {
        $originalEmail = $this->actor->email;

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => 'nuevo@inspecta.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'current_password'
            );

        $this->assertSame(
            $originalEmail,
            $this->actor->fresh()->email
        );
    }

    public function test_incorrect_current_password_rejects_email_change(): void
    {
        $originalEmail = $this->actor->email;

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => 'nuevo@inspecta.test',
                'current_password' => 'Incorrecta2026',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'current_password'
            );

        $this->assertSame(
            $originalEmail,
            $this->actor->fresh()->email
        );
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => 'correo-invalido',
                'current_password' => self::PASSWORD,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_null_email_is_rejected(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_duplicate_email_in_same_organization_is_rejected(): void
    {
        $otherUser = $this->user(
            $this->organization
        );

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => $otherUser->email,
                'current_password' => self::PASSWORD,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_duplicate_email_in_another_organization_is_rejected(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-DUPLICATE'
        );

        $otherUser = $this->user(
            $otherOrganization
        );

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => $otherUser->email,
                'current_password' => self::PASSWORD,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    /*
    |--------------------------------------------------------------------------
    | Protección contra modificaciones no autorizadas
    |--------------------------------------------------------------------------
    */

    public function test_unknown_fields_are_rejected(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre actualizado',
                'unknown_field' => 'value',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'unknown_field'
            );
    }

    public function test_user_cannot_modify_account_status(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre actualizado',
                'status' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertTrue(
            (bool) $this->actor->fresh()->status
        );
    }

    public function test_user_cannot_modify_organization_id(): void
    {
        $originalOrganization =
            $this->actor->organization_id;

        $otherOrganization = $this->clientOrganization(
            'PROFILE-FORBIDDEN-ORG'
        );

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre actualizado',
                'organization_id' => $otherOrganization->id,
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

    public function test_user_cannot_modify_roles_or_permissions(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre actualizado',
                'roles' => ['Administrador'],
                'permissions' => ['users.manage'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'roles',
                'permissions',
            ]);
    }

    public function test_user_cannot_modify_password_through_profile_endpoint(): void
    {
        $originalHash = $this->actor->password;

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre actualizado',
                'password' => 'InspectaNueva2026',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame(
            $originalHash,
            $this->actor->fresh()->password
        );
    }

    public function test_user_cannot_select_another_profile_using_user_id(): void
    {
        $otherUser = $this->user(
            $this->organization
        );

        $originalOtherName = $otherUser->name;

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'user_id' => $otherUser->id,
                'name' => 'Nombre no autorizado',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertSame(
            $originalOtherName,
            $otherUser->fresh()->name
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Integridad y estructura de solicitudes
    |--------------------------------------------------------------------------
    */

    public function test_invalid_email_change_does_not_partially_update_name(): void
    {
        $originalName = $this->actor->name;
        $originalEmail = $this->actor->email;

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre que no debe guardarse',
                'email' => 'nuevo@inspecta.test',
                'current_password' => 'Incorrecta2026',
            ])
            ->assertUnprocessable();

        $this->actor->refresh();

        $this->assertSame(
            $originalName,
            $this->actor->name
        );

        $this->assertSame(
            $originalEmail,
            $this->actor->email
        );
    }

    public function test_empty_json_object_is_rejected(): void
    {
        $this->call(
            'PATCH',
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
            ->assertJsonValidationErrors('body');
    }

    public function test_password_without_profile_fields_is_rejected(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'current_password' => self::PASSWORD,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_json_array_is_rejected(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'Nombre actualizado',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_malformed_json_is_rejected(): void
    {
        $this->call(
            'PATCH',
            self::URL,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
            '{"name":'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_query_parameters_are_rejected(): void
    {
        $this->withToken($this->token)
            ->patchJson(
                self::URL.'?name=OtroNombre',
                [
                    'name' => 'Nombre actualizado',
                ]
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
            ->patch(self::URL, [
                'name' => 'Nombre actualizado',
            ])
            ->assertStatus(415);
    }

    /*
    |--------------------------------------------------------------------------
    | Respuestas y registro de eventos
    |--------------------------------------------------------------------------
    */

    public function test_response_does_not_expose_sensitive_attributes(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre actualizado',
            ])
            ->assertOk()
            ->assertExactJson([
                'message' => 'Perfil actualizado correctamente.',
                'data' => [
                    'id' => $this->actor->id,
                    'name' => 'Nombre actualizado',
                    'email' => $this->actor->email,
                    'organization_id' => $this->actor->organization_id,
                ],
            ]);
    }

    public function test_successful_update_logs_only_changed_field_names(): void
    {
        Log::spy();

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => 'Nombre actualizado',
            ])
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->with(
                'user.profile_updated',
                [
                    'user_id' => $this->actor->id,
                    'changed_fields' => ['name'],
                ]
            );
    }

    public function test_unchanged_profile_does_not_generate_update_event(): void
    {
        Log::spy();

        Log::shouldReceive('info')
            ->withArgs(
                fn ($message, ...$arguments) => $message === 'user.profile_updated'
            )
            ->never();

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ])
            ->assertOk();
    }

    public function test_failed_update_does_not_generate_success_event(): void
    {
        Log::spy();

        Log::shouldReceive('info')
            ->withArgs(
                fn ($message, ...$arguments) => $message === 'user.profile_updated'
            )
            ->never();

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => 'nuevo@inspecta.test',
                'current_password' => 'Incorrecta2026',
            ])
            ->assertUnprocessable();
    }
}
