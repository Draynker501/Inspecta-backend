<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class ProfilePasswordHashingTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const URL = '/api/auth/password';

    private const CURRENT_PASSWORD = 'InspectaActual2026';

    private const NEW_PASSWORD = 'InspectaNueva2026';

    private User $actor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = $this->clientOrganization(
            'PROFILE-HASHING'
        );

        $this->actor = $this->user(
            $organization
        );

        $this->actor->password = Hash::make(
            self::CURRENT_PASSWORD
        );

        $this->actor->save();

        $this->actor->refresh();

        $this->token = $this->actor
            ->createToken('profile-hashing-test')
            ->plainTextToken;
    }

    public function test_la_nueva_contrasena_se_almacena_como_hash(): void
    {
        $this->withToken($this->token)
            ->putJson(self::URL, [
                'current_password' => self::CURRENT_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $storedHash = $this->actor
            ->fresh()
            ->getRawOriginal('password');

        $this->assertNotSame(
            self::NEW_PASSWORD,
            $storedHash
        );

        $this->assertTrue(
            Hash::check(
                self::NEW_PASSWORD,
                $storedHash
            )
        );
    }

    public function test_una_contrasena_con_formato_de_hash_se_trata_como_texto_plano(): void
    {
        $this->assertSame(
            'bcrypt',
            config('hashing.driver')
        );

        $providedHash = Hash::make(
            self::NEW_PASSWORD
        );

        $this->assertSame(
            60,
            strlen($providedHash)
        );

        $this->withToken($this->token)
            ->putJson(self::URL, [
                'current_password' => self::CURRENT_PASSWORD,
                'password' => $providedHash,
                'password_confirmation' => $providedHash,
            ])
            ->assertOk();

        $storedHash = $this->actor
            ->fresh()
            ->getRawOriginal('password');

        $this->assertNotSame(
            $providedHash,
            $storedHash
        );

        $this->assertTrue(
            Hash::check(
                $providedHash,
                $storedHash
            )
        );

        $this->assertFalse(
            Hash::check(
                self::NEW_PASSWORD,
                $storedHash
            )
        );
    }

    public function test_el_hash_generado_respeta_la_configuracion_del_servidor(): void
    {
        $this->withToken($this->token)
            ->putJson(self::URL, [
                'current_password' => self::CURRENT_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $storedHash = $this->actor
            ->fresh()
            ->getRawOriginal('password');

        $this->assertFalse(
            Hash::needsRehash($storedHash)
        );

        $this->assertTrue(
            Hash::check(
                self::NEW_PASSWORD,
                $storedHash
            )
        );
    }

    public function test_el_cambio_de_contrasena_no_aplica_un_hash_adicional_incorrecto(): void
    {
        $this->withToken($this->token)
            ->putJson(self::URL, [
                'current_password' => self::CURRENT_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->actor->refresh();

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

        $this->withToken($this->token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $this->actor->id
            );
    }

    public function test_la_contrasena_incorrecta_no_modifica_el_hash_almacenado(): void
    {
        $originalHash = $this->actor
            ->getRawOriginal('password');

        $this->withToken($this->token)
            ->putJson(self::URL, [
                'current_password' => 'Incorrecta2026',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'current_password',
            ]);

        $this->assertSame(
            $originalHash,
            $this->actor
                ->fresh()
                ->getRawOriginal('password')
        );
    }
}
