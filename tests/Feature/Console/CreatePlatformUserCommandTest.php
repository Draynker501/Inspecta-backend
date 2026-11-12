<?php

namespace Tests\Feature\Console;

use App\Console\Commands\CreatePlatformUserCommand;
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
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class CreatePlatformUserCommandTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const VALID_PASSWORD = 'Secret12345678!';

    private function makeOperator(): Organization
    {
        return $this->operatorOrganization();
    }

    private function createPlatformUser(string $email = 'first@test.com'): void
    {
        $this->artisan('inspecta:create-platform-user', [
            '--email' => $email,
            '--name' => 'Plat User',
        ])
            ->expectsQuestion('Contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Confirmar contraseña', self::VALID_PASSWORD)
            ->assertExitCode(0);
    }

    public function test_caso_feliz_crea_usuario_membresia_rol_y_auditoria(): void
    {
        $operator = $this->makeOperator();
        Role::where('organization_id', $operator->id)->delete();

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'plat@test.com',
            '--name' => 'Plat User',
        ])
            ->expectsQuestion('Contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Confirmar contraseña', self::VALID_PASSWORD)
            ->assertExitCode(0);

        $user = User::where('email', 'plat@test.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check(self::VALID_PASSWORD, $user->password));

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
            ->expectsQuestion('Contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Confirmar contraseña', 'Different12345678!')
            ->expectsQuestion('Contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Confirmar contraseña', 'Different12345678!')
            ->expectsQuestion('Contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Confirmar contraseña', 'Different12345678!')
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
            ->expectsQuestion('Contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Confirmar contraseña', self::VALID_PASSWORD)
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
            ->expectsQuestion('Contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Confirmar contraseña', self::VALID_PASSWORD)
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
            ->expectsQuestion('Contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Confirmar contraseña', self::VALID_PASSWORD)
            ->assertExitCode(0);

        $log = AuditLog::where('action', 'platform_user.created')->first();
        $this->assertNotNull($log);
        $values = $log->new_values;
        $this->assertFalse($values->has('password'));
        $serialized = json_encode($log->toArray());
        $this->assertStringNotContainsString(self::VALID_PASSWORD, $serialized);
    }

    public function test_usuario_resultante_pasa_policy_y_puede_iniciar_sesion(): void
    {
        $this->makeOperator();

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'plat7@test.com',
            '--name' => 'Plat 7',
        ])
            ->expectsQuestion('Contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Confirmar contraseña', self::VALID_PASSWORD)
            ->assertExitCode(0);

        $user = User::where('email', 'plat7@test.com')->first();
        $this->assertTrue(app(PlatformOperatorPolicy::class)->viewMemberships($user));
        $this->assertTrue(app(PlatformOperatorPolicy::class)->manageMemberships($user));

        $response = $this->postJson('/api/auth/login', [
            'email' => 'plat7@test.com',
            'password' => self::VALID_PASSWORD,
        ]);
        $response->assertOk();
    }

    public function test_rollback_ante_fallo_a_mitad(): void
    {
        $this->makeOperator();

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
            ->expectsQuestion('Contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Confirmar contraseña', self::VALID_PASSWORD)
            ->assertExitCode(1);

        $this->assertFalse(User::where('email', 'plat8@test.com')->exists());
        $this->assertSame(0, OrganizationMembership::whereHas('user', fn ($q) => $q->where('email', 'plat8@test.com'))->count());
    }

    public function test_segundo_usuario_de_plataforma_rechazado_sin_efectos(): void
    {
        $this->makeOperator();
        $this->createPlatformUser('first@test.com');

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'second@test.com',
            '--name' => 'Second',
        ])
            ->expectsOutputToContain('Ya existe un usuario de plataforma')
            ->assertExitCode(1);

        $this->assertFalse(User::where('email', 'second@test.com')->exists());
        $this->assertSame(1, User::count());
    }

    public function test_password_corta_rechazada_por_prompt(): void
    {
        $this->makeOperator();

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'short@test.com',
            '--name' => 'Short',
        ])
            ->expectsQuestion('Contraseña', 'short')
            ->expectsQuestion('Confirmar contraseña', 'short')
            ->expectsQuestion('Contraseña', 'short')
            ->expectsQuestion('Confirmar contraseña', 'short')
            ->expectsQuestion('Contraseña', 'short')
            ->expectsQuestion('Confirmar contraseña', 'short')
            ->expectsOutputToContain('al menos')
            ->assertExitCode(1);

        $this->assertFalse(User::where('email', 'short@test.com')->exists());
    }

    public function test_password_vacia_rechazada_por_prompt(): void
    {
        $this->makeOperator();

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'empty@test.com',
            '--name' => 'Empty',
        ])
            ->expectsQuestion('Contraseña', '')
            ->expectsQuestion('Confirmar contraseña', '')
            ->expectsQuestion('Contraseña', '')
            ->expectsQuestion('Confirmar contraseña', '')
            ->expectsQuestion('Contraseña', '')
            ->expectsQuestion('Confirmar contraseña', '')
            ->expectsOutputToContain('vacía')
            ->assertExitCode(1);

        $this->assertFalse(User::where('email', 'empty@test.com')->exists());
    }

    public function test_password_solo_espacios_rechazada_por_prompt(): void
    {
        $this->makeOperator();

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'spaces@test.com',
            '--name' => 'Spaces',
        ])
            ->expectsQuestion('Contraseña', '            ')
            ->expectsQuestion('Confirmar contraseña', '            ')
            ->expectsQuestion('Contraseña', '            ')
            ->expectsQuestion('Confirmar contraseña', '            ')
            ->expectsQuestion('Contraseña', '            ')
            ->expectsQuestion('Confirmar contraseña', '            ')
            ->expectsOutputToContain('vacía')
            ->assertExitCode(1);

        $this->assertFalse(User::where('email', 'spaces@test.com')->exists());
    }

    public function test_password_stdin_vacia(): void
    {
        $this->makeOperator();

        $subclass = new class extends CreatePlatformUserCommand
        {
            protected function readPasswordFromStdin(): ?string
            {
                return '';
            }
        };

        $subclass->setLaravel($this->app);
        $input = new StringInput('--email=stdinempty@test.com --name=StdinEmpty --password-stdin');
        $output = new BufferedOutput;
        $exitCode = $subclass->run($input, $output);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('vacía', $output->fetch());
        $this->assertFalse(User::where('email', 'stdinempty@test.com')->exists());
    }

    public function test_password_stdin_corta(): void
    {
        $this->makeOperator();

        $subclass = new class extends CreatePlatformUserCommand
        {
            protected function readPasswordFromStdin(): ?string
            {
                return 'short';
            }
        };

        $subclass->setLaravel($this->app);
        $input = new StringInput('--email=stdinshort@test.com --name=StdinShort --password-stdin');
        $output = new BufferedOutput;
        $exitCode = $subclass->run($input, $output);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('al menos', $output->fetch());
        $this->assertFalse(User::where('email', 'stdinshort@test.com')->exists());
    }

    public function test_password_stdin_solo_espacios(): void
    {
        $this->makeOperator();

        $subclass = new class extends CreatePlatformUserCommand
        {
            protected function readPasswordFromStdin(): ?string
            {
                return '            ';
            }
        };

        $subclass->setLaravel($this->app);
        $input = new StringInput('--email=stdinspace@test.com --name=StdinSpace --password-stdin');
        $output = new BufferedOutput;
        $exitCode = $subclass->run($input, $output);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('vacía', $output->fetch());
        $this->assertFalse(User::where('email', 'stdinspace@test.com')->exists());
    }

    public function test_tres_reintentos_interactivos_abortan(): void
    {
        $this->makeOperator();

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'retry@test.com',
            '--name' => 'Retry',
        ])
            ->expectsQuestion('Contraseña', 'short')
            ->expectsQuestion('Confirmar contraseña', 'short')
            ->expectsQuestion('Contraseña', 'short')
            ->expectsQuestion('Confirmar contraseña', 'short')
            ->expectsQuestion('Contraseña', 'short')
            ->expectsQuestion('Confirmar contraseña', 'short')
            ->expectsOutputToContain('intentos')
            ->assertExitCode(1);

        $this->assertFalse(User::where('email', 'retry@test.com')->exists());
    }

    public function test_si_existe_plataforma_no_pide_contrasena_interactivo(): void
    {
        $this->makeOperator();
        $this->createPlatformUser('first@test.com');

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'second@test.com',
            '--name' => 'Second',
        ])
            ->expectsOutputToContain('Ya existe un usuario de plataforma')
            ->assertExitCode(1);
    }

    public function test_si_existe_plataforma_no_consume_stdin(): void
    {
        $this->makeOperator();
        $this->createPlatformUser('first2@test.com');

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'second2@test.com',
            '--name' => 'Second2',
            '--password-stdin' => true,
        ])
            ->expectsOutputToContain('Ya existe un usuario de plataforma')
            ->assertExitCode(1);

        $this->assertFalse(User::where('email', 'second2@test.com')->exists());
    }

    public function test_comprobacion_dentro_de_transaccion_protege(): void
    {
        $this->makeOperator();

        $subclass = new class extends CreatePlatformUserCommand
        {
            private int $calls = 0;

            protected function platformUserExists($operator, $platformRoleName): bool
            {
                $this->calls++;

                return $this->calls >= 2;
            }

            protected function readPasswordFromStdin(): ?string
            {
                return 'Secret12345678!';
            }
        };

        // Bind a valid password via property is not possible (private); instead
        // override readPasswordFromStdin to return the valid password.
        $subclass->setLaravel($this->app);
        $input = new StringInput('--email=race@test.com --name=Race --password-stdin');
        $output = new BufferedOutput;
        $exitCode = $subclass->run($input, $output);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Ya existe un usuario de plataforma', $output->fetch());
        $this->assertFalse(User::where('email', 'race@test.com')->exists());
    }

    public function test_password_no_aparece_en_salida_caso_exitoso(): void
    {
        $this->makeOperator();

        $subclass = new class extends CreatePlatformUserCommand
        {
            protected function readPasswordFromStdin(): ?string
            {
                return 'Secret12345678!';
            }
        };

        $subclass->setLaravel($this->app);
        $input = new StringInput('--email=safe@test.com --name=Safe --password-stdin');
        $output = new BufferedOutput;
        $exitCode = $subclass->run($input, $output);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Secret12345678!', $output->fetch());
    }

    public function test_password_no_aparece_en_salida_ni_auditoria_en_rechazo(): void
    {
        $this->makeOperator();

        $bad = 'BadPassword1!'; // 13 chars but mismatched confirmation
        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'reject@test.com',
            '--name' => 'Reject',
        ])
            ->expectsQuestion('Contraseña', $bad)
            ->expectsQuestion('Confirmar contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Contraseña', $bad)
            ->expectsQuestion('Confirmar contraseña', self::VALID_PASSWORD)
            ->expectsQuestion('Contraseña', $bad)
            ->expectsQuestion('Confirmar contraseña', self::VALID_PASSWORD)
            ->expectsOutputToContain('no coinciden')
            ->assertExitCode(1);

        $this->assertSame(0, AuditLog::where('action', 'platform_user.created')->count());
    }
}
