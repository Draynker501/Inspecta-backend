<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    public function test_login_exitoso_devuelve_token_y_datos_del_usuario(): void
    {
        $user = $this->createUser(['email' => 'inspector@example.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.organization_id', $user->organization_id)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email', 'organization_id'],
                    'token',
                ],
            ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_login_con_password_incorrecta_devuelve_401(): void
    {
        $user = $this->createUser(['email' => 'inspector@example.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password-incorrecta',
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_de_usuario_inactivo_devuelve_403(): void
    {
        $user = $this->createUser(['email' => 'inactivo@example.test']);
        $user->forceFill(['status' => false])->save();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_con_datos_invalidos_devuelve_422(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'no-es-un-email',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_devuelve_el_usuario_autenticado(): void
    {
        $user = $this->createUser(['email' => 'inspector@example.test']);
        $token = $user->createToken('inspecta-api')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.organization_id', $user->organization_id);
    }

    public function test_me_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_con_token_invalido_devuelve_401(): void
    {
        $this->withHeader('Authorization', 'Bearer token-inexistente')
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_me_con_usuario_inactivo_devuelve_403(): void
    {
        $user = $this->createUser(['email' => 'inactivo@example.test']);
        $token = $user->createToken('inspecta-api')->plainTextToken;
        $user->forceFill(['status' => false])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertForbidden();
    }

    public function test_logout_revoca_el_token_actual(): void
    {
        $user = $this->createUser(['email' => 'inspector@example.test']);
        $token = $user->createToken('inspecta-api')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_login_aplica_rate_limiting(): void
    {
        $user = $this->createUser(['email' => 'inspector@example.test']);

        for ($intento = 1; $intento <= 5; $intento++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password-incorrecta',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password-incorrecta',
        ])->assertTooManyRequests();
    }

    public function test_la_password_del_usuario_se_almacena_hasheada(): void
    {
        $user = $this->createUser(['email' => 'inspector@example.test']);

        $this->assertNotSame('password', $user->password);
        $this->assertTrue(Hash::check('password', $user->password));
    }
}
