<?php

namespace Tests\Feature\Bootstrap;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Audit\AuditLogger;
use App\Support\Console\StdinPasswordReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class BootstrapOperatorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM_PASSWORD = 'Plataforma-Segura-2026';

    private const CLIENT_PASSWORD = 'Cliente-Seguro-2026';

    public function test_crea_la_operadora_con_tipo_estado_codigo_y_uuid7(): void
    {
        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Consultora INSPECTA'])
            ->assertSuccessful();

        $operator = Organization::query()->where('code', 'INSPECTA-OP')->firstOrFail();

        $this->assertSame(Organization::TYPE_OPERATOR, $operator->type);
        $this->assertTrue($operator->status);
        $this->assertSame('Consultora INSPECTA', $operator->name);
        $this->assertSame(7, Uuid::fromString($operator->id)->getVersion());
        $this->assertSame(0, OrganizationMembership::query()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Role::query()->count());
    }

    public function test_persiste_los_campos_opcionales(): void
    {
        $this->artisan('inspecta:bootstrap-operator', [
            '--name' => 'Consultora INSPECTA',
            '--tax-id' => 'OPR000000000',
            '--address' => 'Oficina central',
            '--phone' => '+52 33 0000 0000',
            '--email' => 'operaciones@inspecta.test',
        ])->assertSuccessful();

        $this->assertDatabaseHas('organizations', [
            'code' => 'INSPECTA-OP',
            'tax_id' => 'OPR000000000',
            'address' => 'Oficina central',
            'phone' => '+52 33 0000 0000',
            'email' => 'operaciones@inspecta.test',
        ]);
    }

    public function test_codigo_por_defecto(): void
    {
        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Consultora INSPECTA'])
            ->assertSuccessful();

        $this->assertDatabaseHas('organizations', ['code' => 'INSPECTA-OP']);
    }

    public function test_codigo_normalizado(): void
    {
        $this->artisan('inspecta:bootstrap-operator', ['--code' => '  inspecta-op  ', '--name' => 'Consultora INSPECTA'])
            ->assertSuccessful();

        $this->assertDatabaseHas('organizations', ['code' => 'INSPECTA-OP']);
    }

    public function test_rechaza_un_codigo_distinto_al_de_la_operadora(): void
    {
        $this->artisan('inspecta:bootstrap-operator', ['--code' => 'OTRA-OP', '--name' => 'Otra'])
            ->assertFailed();

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_reejecucion_es_idempotente(): void
    {
        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Consultora INSPECTA'])
            ->assertSuccessful();

        $operator = Organization::query()->where('code', 'INSPECTA-OP')->firstOrFail();

        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Nombre Distinto'])
            ->assertSuccessful();

        $this->assertSame(1, Organization::query()->where('code', 'INSPECTA-OP')->count());
        $this->assertSame('Consultora INSPECTA', $operator->fresh()->name);
        $this->assertSame(1, AuditLog::query()->where('action', 'operator_organization.created')->count());
    }

    public function test_rechaza_una_organizacion_cliente_con_el_mismo_codigo(): void
    {
        $client = Organization::create([
            'id' => (string) Str::uuid7(),
            'code' => 'INSPECTA-OP',
            'name' => 'Cliente con codigo reservado',
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Consultora INSPECTA'])
            ->assertFailed();

        $this->assertSame(Organization::TYPE_CLIENT, $client->fresh()->type);
        $this->assertSame(1, Organization::query()->count());
    }

    public function test_rechaza_una_operadora_inactiva(): void
    {
        $operator = $this->makeOperator(['status' => false]);

        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Consultora INSPECTA'])
            ->assertFailed();

        $this->assertFalse($operator->fresh()->status);
        $this->assertSame(1, Organization::withTrashed()->count());
    }

    public function test_rechaza_una_operadora_eliminada(): void
    {
        $operator = $this->makeOperator();
        $operator->delete();

        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Consultora INSPECTA'])
            ->assertFailed();

        $this->assertNotNull($operator->fresh()->deleted_at);
        $this->assertSame(1, Organization::withTrashed()->count());
    }

    public function test_rechaza_datos_invalidos(): void
    {
        $this->artisan('inspecta:bootstrap-operator')->assertFailed();

        $this->artisan('inspecta:bootstrap-operator', [
            '--name' => 'Consultora',
            '--email' => 'no-es-email',
        ])->assertFailed();

        $this->artisan('inspecta:bootstrap-operator', [
            '--name' => str_repeat('x', 151),
        ])->assertFailed();

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_rechaza_una_segunda_operadora_activa(): void
    {
        $this->makeOperator(['code' => 'OTRA-OP']);

        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Consultora INSPECTA'])
            ->assertFailed();

        $this->assertSame(1, Organization::query()->where('type', Organization::TYPE_OPERATOR)->count());
    }

    public function test_rollback_completo_si_falla_la_auditoria(): void
    {
        $this->mock(AuditLogger::class, function ($mock): void {
            $mock->shouldReceive('record')->andThrow(new RuntimeException('auditoria no disponible'));
        });

        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Consultora INSPECTA'])
            ->assertFailed();

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_auditoria_de_creacion_sin_actor(): void
    {
        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Consultora INSPECTA'])
            ->assertSuccessful();

        $operator = Organization::query()->where('code', 'INSPECTA-OP')->firstOrFail();
        $audit = AuditLog::query()
            ->where('action', 'operator_organization.created')
            ->firstOrFail();

        $this->assertSame($operator->id, $audit->organization_id);
        $this->assertNull($audit->user_id);
        $this->assertSame('INSPECTA-OP', $audit->new_values['code']);
        $this->assertStringNotContainsString('password', json_encode($audit->new_values));
    }

    public function test_rechaza_actores_invalidos(): void
    {
        $plain = User::factory()->create(['email' => 'plain@example.test']);
        $inactive = User::factory()->create(['email' => 'inactive@example.test', 'status' => false]);

        foreach (['nadie@example.test', $plain->email, $inactive->email] as $actor) {
            $this->artisan('inspecta:bootstrap-operator', [
                '--name' => 'Consultora INSPECTA',
                '--actor' => $actor,
            ])->assertFailed();
        }

        $this->assertSame(0, Organization::query()->where('code', 'INSPECTA-OP')->count());
    }

    public function test_no_crea_membresias_ni_accesos_implicitos(): void
    {
        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Consultora INSPECTA'])
            ->assertSuccessful();

        $operator = Organization::query()->where('code', 'INSPECTA-OP')->firstOrFail();

        $this->assertSame(0, OrganizationMembership::query()->count());
        $this->assertSame(0, UserRole::query()->count());
        $this->assertSame(0, Role::query()->where('organization_id', $operator->id)->count());
    }

    public function test_create_platform_user_rechaza_operadora_inactiva(): void
    {
        $this->makeOperator(['status' => false]);

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'plat@test.test',
            '--name' => 'Plataforma',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_secuencia_completa_operadora_plataforma_cliente(): void
    {
        // 1. Operadora
        $this->artisan('inspecta:bootstrap-operator', ['--name' => 'Consultora INSPECTA'])
            ->assertSuccessful();

        $operator = Organization::query()->where('code', 'INSPECTA-OP')->firstOrFail();

        // 2. Administrador de plataforma
        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'plat@test.test',
            '--name' => 'Plataforma',
        ])
            ->expectsQuestion('Contraseña', self::PLATFORM_PASSWORD)
            ->expectsQuestion('Confirmar contraseña', self::PLATFORM_PASSWORD)
            ->assertSuccessful();

        $platform = User::query()->where('email', 'plat@test.test')->firstOrFail();

        $this->assertTrue($platform->hasPlatformRole());
        $this->assertNotNull(OrganizationMembership::findActive($platform->id, $operator->id));

        // 3. Cliente con el administrador de plataforma como actor
        [$exit, $output] = $this->bootstrapClient([
            '--code' => 'ACME',
            '--name' => 'ACME S.A.',
            '--admin-email' => 'admin@acme.test',
            '--admin-name' => 'Admin ACME',
            '--actor' => $platform->email,
        ], self::CLIENT_PASSWORD);

        $this->assertSame(0, $exit, $output);

        $client = Organization::query()->where('code', 'ACME')->firstOrFail();
        $audit = AuditLog::query()
            ->where('organization_id', $client->id)
            ->where('action', 'client_organization.created')
            ->firstOrFail();

        $this->assertSame($platform->id, $audit->user_id);

        // 4. El administrador de plataforma no obtiene acceso al cliente.
        $platformMembership = OrganizationMembership::query()
            ->where('user_id', $platform->id)
            ->where('organization_id', $client->id)
            ->first();
        $this->assertNull($platformMembership);

        $token = $platform->createToken('tests')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $client->id)
            ->getJson('/api/auth/capabilities')
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_access_denied');

        // 5. El administrador cliente opera en su organizacion.
        $login = $this->postJson('/api/auth/login', [
            'email' => 'admin@acme.test',
            'password' => self::CLIENT_PASSWORD,
        ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$login->json('data.token'))
            ->getJson('/api/auth/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'ACME');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOperator(array $attributes = []): Organization
    {
        return Organization::create(array_merge([
            'id' => (string) Str::uuid7(),
            'code' => 'INSPECTA-OP',
            'name' => 'Consultora INSPECTA',
            'type' => Organization::TYPE_OPERATOR,
            'status' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    private function bootstrapClient(array $options, string $password): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $password);
        rewind($stream);

        $this->app->instance(StdinPasswordReader::class, new StdinPasswordReader($stream));

        $buffer = new BufferedOutput;
        $exit = Artisan::call(
            'inspecta:bootstrap-client',
            array_merge($options, ['--password-stdin' => true]),
            $buffer,
        );

        return [$exit, $buffer->fetch()];
    }
}
