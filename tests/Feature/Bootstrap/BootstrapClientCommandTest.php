<?php

namespace Tests\Feature\Bootstrap;

use App\Authorization\ClientRoleTemplate;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Audit\AuditLogger;
use App\Support\Bootstrap\ClientBootstrap;
use App\Support\Console\StdinPasswordReader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class BootstrapClientCommandTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const PASSWORD = 'Cliente-Seguro-2026';

    private ?User $platformActor = null;

    public function test_crea_organizacion_administrador_membresia_y_roles(): void
    {
        $this->runBootstrap();

        $organization = Organization::query()->where('code', 'ACME')->firstOrFail();

        $this->assertSame(Organization::TYPE_CLIENT, $organization->type);
        $this->assertTrue($organization->status);
        $this->assertSame('ACME S.A.', $organization->name);
        $this->assertSame(7, Uuid::fromString($organization->id)->getVersion());

        $user = User::query()->where('email', 'admin@acme.test')->firstOrFail();
        $this->assertSame($organization->id, $user->organization_id);
        $this->assertTrue((bool) $user->status);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertSame(7, Uuid::fromString($user->id)->getVersion());

        $this->assertDatabaseHas('organization_memberships', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $roles = Role::query()->where('organization_id', $organization->id)->get()->keyBy('name');
        $this->assertCount(3, $roles);
        $this->assertSame('admin', $roles[ClientRoleTemplate::ADMIN]->system_key);
        $this->assertSame('inspector', $roles['Inspector']->system_key);
        $this->assertSame('supervisor', $roles['Supervisor']->system_key);

        $permissions = RolePermission::query()
            ->where('role_id', $roles[ClientRoleTemplate::ADMIN]->id)
            ->pluck('permission')
            ->sort()
            ->values()
            ->all();

        $expected = array_map(
            static fn ($permission) => $permission->value,
            ClientRoleTemplate::byName(ClientRoleTemplate::ADMIN)['permissions'],
        );
        sort($expected);

        $this->assertSame($expected, $permissions);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $roles[ClientRoleTemplate::ADMIN]->id,
            'organization_id' => $organization->id,
        ]);

        $actions = AuditLog::query()->where('organization_id', $organization->id)->pluck('action')->all();
        $this->assertContains('client_organization.created', $actions);
        $this->assertContains('client_user.created', $actions);
        $this->assertContains('client_membership.created', $actions);
        $this->assertContains('role.assigned', $actions);

        $audit = json_encode(AuditLog::query()->where('organization_id', $organization->id)->get()->toArray());
        $this->assertStringNotContainsString(self::PASSWORD, (string) $audit);
    }

    public function test_el_administrador_puede_iniciar_sesion_y_seleccionar_su_organizacion(): void
    {
        $this->runBootstrap();

        $organization = Organization::query()->where('code', 'ACME')->firstOrFail();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'admin@acme.test',
            'password' => self::PASSWORD,
        ])->assertOk();

        $token = (string) $login->json('data.token');
        $this->assertNotSame('', $token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'ACME')
            ->assertJsonPath('data.0.id', $organization->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/auth/capabilities')
            ->assertOk()
            ->assertJsonPath('organization_id', $organization->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/checklists')
            ->assertOk();
    }

    public function test_no_permite_acceder_a_otra_organizacion(): void
    {
        $this->runBootstrap();

        $this->runBootstrap([
            '--code' => 'OTRA',
            '--name' => 'Otra S.A.',
            '--admin-email' => 'admin@otra.test',
        ]);

        $orgA = Organization::query()->where('code', 'ACME')->firstOrFail();
        $orgB = Organization::query()->where('code', 'OTRA')->firstOrFail();

        $adminA = User::query()->where('email', 'admin@acme.test')->firstOrFail();
        $token = $adminA->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $orgA->id)
            ->getJson('/api/auth/capabilities')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $orgB->id)
            ->getJson('/api/auth/capabilities')
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_access_denied');
    }

    public function test_reejecutar_el_mismo_codigo_falla_sin_duplicar(): void
    {
        $this->runBootstrap();

        $organization = Organization::query()->where('code', 'ACME')->firstOrFail();

        $this->artisan('inspecta:bootstrap-client', $this->commandOptions())
            ->expectsQuestion('Contrasena del administrador', self::PASSWORD)
            ->expectsQuestion('Confirmar contrasena', self::PASSWORD)
            ->assertFailed();

        $this->assertSame(1, Organization::query()->where('code', 'ACME')->count());
        $this->assertSame(1, User::query()->where('email', 'admin@acme.test')->count());
        $this->assertSame(3, Role::query()->where('organization_id', $organization->id)->count());
    }

    public function test_rechaza_email_existente_sin_flag_de_vinculacion(): void
    {
        $this->runBootstrap();

        $this->artisan('inspecta:bootstrap-client', $this->commandOptions([
            '--code' => 'NUEVA',
            '--name' => 'Nueva S.A.',
            '--admin-email' => 'admin@acme.test',
            '--admin-name' => 'Otro Nombre',
        ]))->expectsQuestion('Contrasena del administrador', self::PASSWORD)
            ->expectsQuestion('Confirmar contrasena', self::PASSWORD)
            ->assertFailed();

        $this->assertSame(0, Organization::query()->where('code', 'NUEVA')->count());
    }

    public function test_vincula_usuario_existente_sin_modificarlo(): void
    {
        $existing = User::factory()->create([
            'email' => 'global@example.test',
            'password' => 'Clave-Original-123',
            'status' => true,
        ]);

        $this->artisan('inspecta:bootstrap-client', $this->commandOptions([
            '--code' => 'VINC',
            '--name' => 'Vinculada S.A.',
            '--admin-email' => $existing->email,
            '--admin-name' => 'Ignorado',
            '--existing-user' => true,
        ]))->assertSuccessful();

        $organization = Organization::query()->where('code', 'VINC')->firstOrFail();
        $fresh = $existing->fresh();

        $this->assertSame(1, User::query()->where('email', 'global@example.test')->count());
        $this->assertSame($existing->name, $fresh->name);
        $this->assertTrue(Hash::check('Clave-Original-123', $fresh->password));

        $this->assertDatabaseHas('organization_memberships', [
            'user_id' => $existing->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $adminRole = Role::query()
            ->where('organization_id', $organization->id)
            ->where('system_key', 'admin')
            ->firstOrFail();

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $existing->id,
            'role_id' => $adminRole->id,
            'organization_id' => $organization->id,
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'client_user.created',
        ]);
    }

    public function test_rechaza_usuario_existente_inactivo(): void
    {
        $inactive = User::factory()->create([
            'email' => 'inactivo@example.test',
            'status' => false,
        ]);

        $this->artisan('inspecta:bootstrap-client', $this->commandOptions([
            '--code' => 'INACT',
            '--name' => 'Inactiva S.A.',
            '--admin-email' => $inactive->email,
            '--existing-user' => true,
        ]))->assertFailed();

        $this->assertSame(0, Organization::query()->where('code', 'INACT')->count());

        $this->artisan('inspecta:bootstrap-client', $this->commandOptions([
            '--code' => 'NOEXISTE',
            '--name' => 'Sin usuario S.A.',
            '--admin-email' => 'nadie@example.test',
            '--existing-user' => true,
        ]))->assertFailed();

        $this->assertSame(0, Organization::query()->where('code', 'NOEXISTE')->count());
    }

    public function test_rechaza_el_codigo_reservado_de_la_operadora(): void
    {
        $this->artisan('inspecta:bootstrap-client', $this->commandOptions([
            '--code' => ClientBootstrap::RESERVED_OPERATOR_CODE,
            '--name' => 'Intrusa S.A.',
            '--admin-email' => 'admin@intrusa.test',
            '--admin-name' => 'Admin',
        ]))->assertFailed();

        $this->assertSame(1, Organization::query()->where('code', 'INSPECTA-OP')->count());
        $this->assertSame(0, Organization::query()->where('code', 'INSPECTA-OP')->where('type', Organization::TYPE_CLIENT)->count());
    }

    public function test_rechaza_el_codigo_reservado_normalizado(): void
    {
        $this->artisan('inspecta:bootstrap-client', $this->commandOptions([
            '--code' => '  inspecta-op  ',
            '--name' => 'Intrusa S.A.',
            '--admin-email' => 'admin@intrusa.test',
            '--admin-name' => 'Admin',
        ]))->assertFailed();

        $this->assertSame(0, Organization::query()->where('code', 'INSPECTA-OP')->where('type', Organization::TYPE_CLIENT)->count());
    }

    public function test_normaliza_el_codigo_antes_de_crear(): void
    {
        [$exit, $output] = $this->runStdinBootstrap(['--code' => ' acme '], self::PASSWORD);

        $this->assertSame(0, $exit, $output);
        $this->assertDatabaseHas('organizations', ['code' => 'ACME']);
    }

    public function test_el_servicio_tambien_rechaza_el_codigo_reservado(): void
    {
        try {
            app(ClientBootstrap::class)->provision(
                ['code' => 'inspecta-op', 'name' => 'Intrusa S.A.'],
                ['email' => 'admin@intrusa.test', 'name' => 'Admin', 'password' => self::PASSWORD],
                'admin@actor.test',
            );
            $this->fail('El servicio acepto el codigo reservado.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        $this->assertSame(0, Organization::query()->where('code', 'INSPECTA-OP')->where('type', Organization::TYPE_CLIENT)->count());
    }

    public function test_actor_valido_registra_la_auditoria(): void
    {
        $operator = $this->operatorOrganization();
        $actor = $this->platformConsultant($operator, []);

        [$exit, $output] = $this->runStdinBootstrap([
            '--code' => 'ACT',
            '--name' => 'Act S.A.',
            '--admin-email' => 'admin@act.test',
            '--admin-name' => 'Admin Act',
            '--actor' => $actor->email,
        ], self::PASSWORD);

        $this->assertSame(0, $exit, $output);

        $organization = Organization::query()->where('code', 'ACT')->firstOrFail();
        $audit = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where('action', 'client_organization.created')
            ->firstOrFail();

        $this->assertSame($actor->id, $audit->user_id);
    }

    public function test_actor_invalido_es_rechazado(): void
    {
        $plain = User::factory()->create(['email' => 'plain@example.test']);
        $before = Organization::query()->count();

        foreach (['nadie@example.test', $plain->email] as $actor) {
            [$exit] = $this->runStdinBootstrap([
                '--code' => 'ACTINV',
                '--name' => 'Act Inv S.A.',
                '--admin-email' => 'admin@actinvt.test',
                '--admin-name' => 'Admin',
                '--actor' => $actor,
            ], self::PASSWORD);

            $this->assertNotSame(0, $exit, "El actor {$actor} fue aceptado.");
        }

        $this->assertSame($before, Organization::query()->count());
    }

    public function test_contrasena_por_stdin_y_sin_exposicion_en_salida(): void
    {
        [$exit, $output] = $this->runStdinBootstrap(
            ['--code' => 'STDIN', '--admin-email' => 'admin@stdin.test'],
            self::PASSWORD,
            self::PASSWORD."\n",
        );

        $this->assertSame(0, $exit, $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);

        $user = User::query()->where('email', 'admin@stdin.test')->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));

        $login = $this->postJson('/api/auth/login', [
            'email' => 'admin@stdin.test',
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertNotSame('', (string) $login->json('data.token'));
    }

    public function test_stdin_vacio_falla_sin_escribir(): void
    {
        [$exit, $output] = $this->runStdinBootstrap(
            ['--code' => 'VACIO', '--admin-email' => 'admin@vacio.test'],
            self::PASSWORD,
            '',
        );

        $this->assertNotSame(0, $exit);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
        $this->assertSame(0, Organization::query()->where('code', 'VACIO')->count());
    }

    public function test_stdin_cerrado_falla_sin_escribir(): void
    {
        $stream = fopen('php://memory', 'r');
        fclose($stream);

        $this->app->instance(StdinPasswordReader::class, new StdinPasswordReader($stream));

        $buffer = new BufferedOutput;
        $exit = Artisan::call('inspecta:bootstrap-client', $this->commandOptions([
            '--code' => 'CERRADO',
            '--admin-email' => 'admin@cerrado.test',
            '--password-stdin' => true,
        ]), $buffer);

        $this->assertNotSame(0, $exit);
        $this->assertStringNotContainsString(self::PASSWORD, $buffer->fetch());
        $this->assertSame(0, Organization::query()->where('code', 'CERRADO')->count());
    }

    public function test_rollback_completo_si_falla_la_auditoria(): void
    {
        $this->actor();

        $before = $this->counts();

        $this->mock(AuditLogger::class, function ($mock): void {
            $mock->shouldReceive('record')->andThrow(new RuntimeException('auditoria no disponible'));
        });

        $this->artisan('inspecta:bootstrap-client', $this->commandOptions())
            ->expectsQuestion('Contrasena del administrador', self::PASSWORD)
            ->expectsQuestion('Confirmar contrasena', self::PASSWORD)
            ->assertFailed();

        $this->assertSame($before, $this->counts());
    }

    public function test_rollback_si_falla_una_auditoria_posterior(): void
    {
        $this->actor();

        $before = $this->counts();

        $calls = 0;

        $this->mock(AuditLogger::class, function ($mock) use (&$calls): void {
            $mock->shouldReceive('record')->andReturnUsing(function () use (&$calls) {
                $calls++;

                if ($calls >= 2) {
                    throw new RuntimeException('auditoria posterior no disponible');
                }

                return new AuditLog;
            });
        });

        $this->artisan('inspecta:bootstrap-client', $this->commandOptions())
            ->expectsQuestion('Contrasena del administrador', self::PASSWORD)
            ->expectsQuestion('Confirmar contrasena', self::PASSWORD)
            ->assertFailed();

        $this->assertSame($before, $this->counts());
    }

    public function test_valida_codigo_nombre_y_contrasena(): void
    {
        $this->artisan('inspecta:bootstrap-client', $this->commandOptions([
            '--code' => '1MAL',
            '--name' => 'X',
            '--admin-email' => 'no-es-email',
            '--admin-name' => 'X',
        ]))->assertFailed();

        $this->artisan('inspecta:bootstrap-client', $this->commandOptions([
            '--code' => 'OK',
            '--name' => 'Valida S.A.',
            '--admin-email' => 'admin@ok.test',
            '--admin-name' => 'Admin',
        ]))->expectsQuestion('Contrasena del administrador', 'corta')
            ->expectsQuestion('Confirmar contrasena', 'corta')
            ->assertFailed();

        $this->assertSame(0, Organization::query()->where('code', 'OK')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandOptions(array $overrides = []): array
    {
        $options = [
            '--code' => 'ACME',
            '--name' => 'ACME S.A.',
            '--admin-email' => 'admin@acme.test',
            '--admin-name' => 'Admin ACME',
        ];

        if (! array_key_exists('--actor', $overrides)) {
            $options['--actor'] = $this->actor()->email;
        }

        return array_merge($options, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function runBootstrap(array $overrides = []): void
    {
        $this->artisan('inspecta:bootstrap-client', $this->commandOptions($overrides))
            ->expectsQuestion('Contrasena del administrador', self::PASSWORD)
            ->expectsQuestion('Confirmar contrasena', self::PASSWORD)
            ->assertSuccessful();
    }

    /**
     * Ejecuta el comando por la ruta --password-stdin con una entrada inyectada.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: int, 1: string}
     */
    private function runStdinBootstrap(array $overrides, string $password, ?string $contents = null): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents ?? $password);
        rewind($stream);

        $this->app->instance(StdinPasswordReader::class, new StdinPasswordReader($stream));

        $buffer = new BufferedOutput;
        $exit = Artisan::call(
            'inspecta:bootstrap-client',
            $this->commandOptions(array_merge($overrides, ['--password-stdin' => true])),
            $buffer,
        );

        return [$exit, $buffer->fetch()];
    }

    private function actor(): User
    {
        return $this->platformActor ??= $this->createPlatformActor();
    }

    private function createPlatformActor(): User
    {
        $operator = Organization::create([
            'id' => (string) Uuid::uuid7(),
            'code' => 'INSPECTA-OP',
            'name' => 'Consultora INSPECTA',
            'type' => Organization::TYPE_OPERATOR,
            'status' => true,
        ]);

        $actor = User::factory()->forOrganization($operator)->create(['status' => true]);

        OrganizationMembership::create([
            'user_id' => $actor->id,
            'organization_id' => $operator->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        app(TenantContext::class)->runAs($operator->id, function () use ($actor, $operator): void {
            $role = Role::create([
                'id' => (string) Uuid::uuid7(),
                'organization_id' => $operator->id,
                'name' => (string) config('tenancy.roles.platform_admin'),
                'description' => 'Rol de plataforma',
                'status' => true,
            ]);

            $actor->assignRole($role);
        });

        return $actor;
    }

    private function clientOrgCount(): int
    {
        return Organization::query()->where('type', Organization::TYPE_CLIENT)->count();
    }

    /**
     * @return array<int, int>
     */
    private function counts(): array
    {
        return [
            $this->clientOrgCount(),
            User::query()->count(),
            Role::query()->count(),
            OrganizationMembership::query()->count(),
            UserRole::query()->count(),
            AuditLog::query()->count(),
        ];
    }
}
