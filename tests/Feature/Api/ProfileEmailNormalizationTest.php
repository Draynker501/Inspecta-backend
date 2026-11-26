<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ProfileEmailNormalizationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const URL = '/api/auth/me';

    private const CURRENT_PASSWORD = 'InspectaActual2026';

    private const CURRENT_EMAIL = 'perfil@inspecta.com';

    private User $actor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = $this->clientOrganization(
            'PROFILE-EMAIL'
        );

        $this->actor = $this->user($organization);

        $this->actor->email = self::CURRENT_EMAIL;

        $this->actor->password = Hash::make(
            self::CURRENT_PASSWORD
        );

        $this->actor->email_verified_at = now();

        $this->actor->save();

        $this->actor->refresh();

        $this->token = $this->actor
            ->createToken('profile-email-test')
            ->plainTextToken;
    }

    public function test_el_correo_se_normaliza_a_minusculas(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => 'NUEVO@INSPECTA.COM',
                'current_password' => self::CURRENT_PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.email',
                'nuevo@inspecta.com'
            );

        $this->assertSame(
            'nuevo@inspecta.com',
            $this->actor->fresh()->email
        );
    }

    public function test_el_correo_se_normaliza_eliminando_espacios_externos(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => '  Nuevo@Inspecta.com  ',
                'current_password' => self::CURRENT_PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.email',
                'nuevo@inspecta.com'
            );

        $this->assertSame(
            'nuevo@inspecta.com',
            $this->actor->fresh()->email
        );
    }

    public function test_el_mismo_correo_en_mayusculas_no_requiere_contrasena(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => strtoupper(self::CURRENT_EMAIL),
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.email',
                self::CURRENT_EMAIL
            );

        $this->assertSame(
            self::CURRENT_EMAIL,
            $this->actor->fresh()->email
        );
    }

    public function test_el_mismo_correo_normalizado_conserva_su_verificacion(): void
    {
        $originalVerification = $this->actor
            ->getRawOriginal('email_verified_at');

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => '  PERFIL@INSPECTA.COM  ',
            ])
            ->assertOk();

        $this->assertSame(
            $originalVerification,
            $this->actor
                ->fresh()
                ->getRawOriginal('email_verified_at')
        );
    }

    public function test_un_correo_distinto_normalizado_requiere_contrasena(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => 'NUEVO@INSPECTA.COM',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'current_password',
            ]);

        $this->assertSame(
            self::CURRENT_EMAIL,
            $this->actor->fresh()->email
        );
    }

    public function test_un_correo_distinto_normalizado_elimina_la_verificacion(): void
    {
        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => 'NUEVO@INSPECTA.COM',
                'current_password' => self::CURRENT_PASSWORD,
            ])
            ->assertOk();

        $this->assertNull(
            $this->actor->fresh()->email_verified_at
        );
    }

    public function test_la_unicidad_del_correo_se_valida_despues_de_normalizar(): void
    {
        $otherOrganization = $this->clientOrganization(
            'PROFILE-EMAIL-OTHER'
        );

        $otherUser = $this->user($otherOrganization);

        $otherUser->email = 'ocupado@inspecta.com';

        $otherUser->save();

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => '  OCUPADO@INSPECTA.COM  ',
                'current_password' => self::CURRENT_PASSWORD,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
            ]);

        $this->assertSame(
            self::CURRENT_EMAIL,
            $this->actor->fresh()->email
        );

        $this->assertSame(
            'ocupado@inspecta.com',
            $otherUser->fresh()->email
        );
    }

    public function test_la_normalizacion_no_modifica_la_contrasena_actual(): void
    {
        $passwordWithSpaces = ' InspectaActual2026 ';

        $this->actor->password = Hash::make(
            $passwordWithSpaces
        );

        $this->actor->save();

        $this->withToken($this->token)
            ->patchJson(self::URL, [
                'email' => 'NUEVO@INSPECTA.COM',
                'current_password' => $passwordWithSpaces,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.email',
                'nuevo@inspecta.com'
            );

        $this->assertTrue(
            Hash::check(
                $passwordWithSpaces,
                $this->actor->fresh()->password
            )
        );
    }
}
