<?php

namespace Tests\Feature\Bootstrap;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Bootstrap\ClientBootstrap;
use App\Support\Bootstrap\OperatorBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * Aplica las reglas directamente sobre los servicios, sin pasar por Artisan.
 */
class BootstrapServicesHardeningTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const PASSWORD = 'Cliente-Seguro-2026';

    public function test_servicio_cliente_rechaza_nombre_invalido(): void
    {
        $actor = $this->actor();

        $this->assertRejected(fn () => $this->clientService([
            'code' => 'HARD',
            'name' => '   ',
        ], $actor->email));

        $this->assertSame(0, Organization::query()->where('code', 'HARD')->count());
    }

    public function test_servicio_cliente_rechaza_email_invalido(): void
    {
        $actor = $this->actor();

        $this->assertRejected(fn () => $this->clientService(
            ['code' => 'HARD', 'name' => 'Hard S.A.'],
            $actor->email,
            ['email' => 'no-es-email', 'name' => 'Admin'],
        ));

        $this->assertRejected(fn () => $this->clientService(
            ['code' => 'HARD', 'name' => 'Hard S.A.', 'email' => 'invalido'],
            $actor->email,
        ));

        $this->assertSame(0, Organization::query()->where('code', 'HARD')->count());
    }

    public function test_servicio_cliente_rechaza_contrasena_insuficiente(): void
    {
        $actor = $this->actor();

        $this->assertRejected(fn () => $this->clientService(
            ['code' => 'HARD', 'name' => 'Hard S.A.'],
            $actor->email,
            ['email' => 'admin@hard.test', 'name' => 'Admin', 'password' => 'corta'],
        ));

        $this->assertSame(0, Organization::query()->where('code', 'HARD')->count());
    }

    public function test_servicio_cliente_rechaza_usuario_existente_inactivo(): void
    {
        $actor = $this->actor();
        $inactive = User::factory()->create(['email' => 'inactivo@hard.test', 'status' => false]);

        $this->expectException(RuntimeException::class);

        try {
            $this->clientService(
                ['code' => 'HARD', 'name' => 'Hard S.A.'],
                $actor->email,
                ['email' => $inactive->email, 'link_existing' => true],
            );
        } finally {
            $this->assertSame(0, Organization::query()->where('code', 'HARD')->count());
        }
    }

    public function test_servicio_cliente_rechaza_actor_null(): void
    {
        $this->assertRejected(fn () => $this->clientService(
            ['code' => 'HARD', 'name' => 'Hard S.A.'],
            null,
        ));

        $this->assertSame(0, Organization::query()->where('code', 'HARD')->count());
    }

    public function test_servicio_cliente_rechaza_actor_inexistente_o_sin_plataforma(): void
    {
        $plain = User::factory()->create(['email' => 'plain@hard.test']);

        foreach (['nadie@hard.test', $plain->email] as $actorEmail) {
            $this->assertRejected(fn () => $this->clientService(
                ['code' => 'HARD', 'name' => 'Hard S.A.'],
                $actorEmail,
            ));
        }

        $this->assertSame(0, Organization::query()->where('code', 'HARD')->count());
    }

    public function test_servicio_cliente_con_actor_valido_crea_y_audita(): void
    {
        $actor = $this->actor();

        $result = $this->clientService(
            ['code' => 'HARD', 'name' => 'Hard S.A.'],
            $actor->email,
        );

        $organization = $result['organization'];

        $this->assertSame(Organization::TYPE_CLIENT, $organization->type);
        $this->assertTrue($organization->status);
        $this->assertSame(7, Uuid::fromString($organization->id)->getVersion());

        $audit = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where('action', 'client_organization.created')
            ->firstOrFail();

        $this->assertSame($actor->id, $audit->user_id);
    }

    public function test_servicio_operadora_primer_bootstrap_sin_actor(): void
    {
        $result = app(OperatorBootstrap::class)->provision(['name' => 'Consultora INSPECTA']);

        $this->assertTrue($result['created']);
        $this->assertSame('INSPECTA-OP', $result['organization']->code);
        $this->assertSame(Organization::TYPE_OPERATOR, $result['organization']->type);

        $audit = AuditLog::query()->where('action', 'operator_organization.created')->firstOrFail();
        $this->assertNull($audit->user_id);
    }

    public function test_servicio_operadora_rechaza_nombre_invalido(): void
    {
        $this->assertRejected(fn () => app(OperatorBootstrap::class)->provision(['name' => '']));

        $this->assertSame(0, Organization::query()->where('type', Organization::TYPE_OPERATOR)->count());
    }

    public function test_servicio_operadora_rechaza_actor_invalido(): void
    {
        $this->assertRejected(fn () => app(OperatorBootstrap::class)->provision(
            ['name' => 'Consultora INSPECTA'],
            'nadie@hard.test',
        ));

        $this->assertSame(0, Organization::query()->where('type', Organization::TYPE_OPERATOR)->count());
    }

    public function test_servicio_operadora_es_idempotente(): void
    {
        $service = app(OperatorBootstrap::class);

        $first = $service->provision(['name' => 'Consultora INSPECTA']);
        $second = $service->provision([]);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['organization']->id, $second['organization']->id);
        $this->assertSame(1, AuditLog::query()->where('action', 'operator_organization.created')->count());
    }

    private function actor(): User
    {
        $operator = $this->operatorOrganization();

        return $this->platformConsultant($operator, []);
    }

    /**
     * @param  array{code: string, name: string, email?: ?string}  $organization
     * @param  array{email?: string, name?: string, password?: string, link_existing?: bool}  $adminOverrides
     * @return array{organization: Organization, user: User, membership: OrganizationMembership}
     */
    private function clientService(array $organization, ?string $actorEmail, array $adminOverrides = []): array
    {
        $admin = array_merge([
            'email' => 'admin@hard.test',
            'name' => 'Admin Hard',
            'password' => self::PASSWORD,
        ], $adminOverrides);

        return app(ClientBootstrap::class)->provision($organization, $admin, $actorEmail);
    }

    private function clientOrgCount(): int
    {
        return Organization::query()->where('type', Organization::TYPE_CLIENT)->count();
    }

    private function assertRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('La operacion fue aceptada y debia rechazarse.');
        } catch (InvalidArgumentException|RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
