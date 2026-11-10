<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Policies\PlatformOperatorPolicy;
use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class CreatePlatformUserCommandTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function makeOperator(): Organization
    {
        return $this->operatorOrganization();
    }

    public function test_caso_feliz_crea_usuario_membresia_rol_y_auditoria(): void
    {
        $operator = $this->makeOperator();
        // role may or may not exist; command creates it
        Role::where('organization_id', $operator->id)->delete();

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'plat@test.com',
            '--name' => 'Plat User',
        ])
            ->expectsQuestion('Contraseña', 'secret123')
            ->expectsQuestion('Confirmar contraseña', 'secret123')
            ->assertExitCode(0);

        $user = User::where('email', 'plat@test.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('secret123', $user->password));

        $this->assertNotNull(OrganizationMembership::findActive($user->id, $operator->id));

        $platformRoleName = (string) config('tenancy.roles.platform_admin');
        $role = Role::where('organization_id', $operator->id)->where('name', $platformRoleName)->first();
        $this->assertNotNull($role);

        $this->assertTrue(app(PlatformOperatorPolicy::class)->manageMemberships($user));

        $log = AuditLog::where('action', 'platform_user.created')->first();
        $this->assertNotNull($log);
        $this->assertSame($operator->id, $log->organization_id);
    }

    public function test_contraseñas_distintas_en_confirmacion_falla_sin_efectos(): void
    {
        $this->makeOperator();

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'plat2@test.com',
            '--name' => 'Plat 2',
        ])
            ->expectsQuestion('Contraseña', 'secret123')
            ->expectsQuestion('Confirmar contraseña', 'different')
            ->assertExitCode(1);

        $this->assertFalse(User::where('email', 'plat2@test.com')->exists());
        $this->assertSame(0, AuditLog::where('action', 'platform_user.created')->count());
    }

    public function test_operadora_ausente_falla_con_mensaje(): void
    {
        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'plat3@test.com',
            '--name' => 'Plat 3',
        ])
            ->expectsQuestion('Contraseña', 'secret123')
            ->expectsQuestion('Confirmar contraseña', 'secret123')
            ->expectsOutputToContain('no existe')
            ->assertExitCode(1);
    }

    public function test_email_duplicado_no_modifica_nada(): void
    {
        $this->makeOperator();

        $existing = User::factory()->create(['email' => 'dup@test.com']);
        $passwordBefore = $existing->password;

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'dup@test.com',
            '--name' => 'Dup',
        ])
            ->expectsQuestion('Contraseña', 'secret123')
            ->expectsQuestion('Confirmar contraseña', 'secret123')
            ->expectsOutputToContain('Ya existe')
            ->assertExitCode(1);

        $this->assertSame(1, User::where('email', 'dup@test.com')->count());
        $this->assertSame($passwordBefore, User::where('email', 'dup@test.com')->value('password'));
    }

    public function test_rol_existente_se_reutiliza(): void
    {
        $operator = $this->makeOperator();
        $platformRoleName = (string) config('tenancy.roles.platform_admin');
        $existing = Role::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $operator->id,
            'name' => $platformRoleName,
            'description' => 'existente',
            'status' => true,
        ]);

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'plat4@test.com',
            '--name' => 'Plat 4',
        ])
            ->expectsQuestion('Contraseña', 'secret123')
            ->expectsQuestion('Confirmar contraseña', 'secret123')
            ->assertExitCode(0);

        $roles = Role::where('organization_id', $operator->id)->where('name', $platformRoleName)->get();
        $this->assertCount(1, $roles);
        $this->assertSame($existing->id, $roles->first()->id);
    }

    public function test_auditoria_sin_password_ni_hash(): void
    {
        $this->makeOperator();

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'plat5@test.com',
            '--name' => 'Plat 5',
        ])
            ->expectsQuestion('Contraseña', 'secret123')
            ->expectsQuestion('Confirmar contraseña', 'secret123')
            ->assertExitCode(0);

        $log = AuditLog::where('action', 'platform_user.created')->first();
        $this->assertNotNull($log);
        $values = $log->new_values;
        $this->assertFalse($values->has('password'));
        $serialized = json_encode($log->toArray());
        $this->assertStringNotContainsString('secret123', $serialized);
    }

    public function test_usuario_resultante_pasa_policy_y_puede_iniciar_sesion(): void
    {
        $this->makeOperator();

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'plat7@test.com',
            '--name' => 'Plat 7',
        ])
            ->expectsQuestion('Contraseña', 'secret123')
            ->expectsQuestion('Confirmar contraseña', 'secret123')
            ->assertExitCode(0);

        $user = User::where('email', 'plat7@test.com')->first();
        $this->assertTrue(app(PlatformOperatorPolicy::class)->viewMemberships($user));
        $this->assertTrue(app(PlatformOperatorPolicy::class)->manageMemberships($user));

        // login
        $response = $this->postJson('/api/auth/login', [
            'email' => 'plat7@test.com',
            'password' => 'secret123',
        ]);
        $response->assertOk();
    }

    public function test_rollback_ante_fallo_a_mitad(): void
    {
        $this->makeOperator();

        // Force failure by making AuditLogger throw via a subclass binding
        $this->app->bind(AuditLogger::class, function () {
            return new class
            {
                public function record(...$args)
                {
                    throw new \RuntimeException('boom');
                }
            };
        });

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'plat8@test.com',
            '--name' => 'Plat 8',
        ])
            ->expectsQuestion('Contraseña', 'secret123')
            ->expectsQuestion('Confirmar contraseña', 'secret123')
            ->assertExitCode(1);

        $this->assertFalse(User::where('email', 'plat8@test.com')->exists());
        $this->assertSame(0, OrganizationMembership::whereHas('user', fn ($q) => $q->where('email', 'plat8@test.com'))->count());
    }
}
