<?php

namespace Tests\Feature\Audit;

use App\Authorization\Permission;
use App\Exceptions\AuditLogImmutableException;
use App\Models\AuditLog;
use App\Models\OrganizationMembership;
use App\Models\Plant;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_fuera_de_frontera_conserva_comportamiento_general_de_auditoria(): void
    {
        $a = $this->organization('LEGACY-A');
        $b = $this->organization('LEGACY-B');
        app(TenantContext::class)->set($b->id);
        $plant = Plant::create(['id' => (string) Str::uuid7(), 'name' => 'Ajena', 'code' => 'LEGACY', 'status' => true]);
        app(TenantContext::class)->set($a->id);

        $log = app(AuditLogger::class)->record('test.legacy', $plant);
        $this->assertSame($a->id, $log->organization_id);
    }

    public function test_rechaza_auditable_de_otra_organizacion_sin_escribir(): void
    {
        $a = $this->organization('AUDIT-A');
        $b = $this->organization('AUDIT-B');
        app(TenantContext::class)->set($b->id);
        $plant = Plant::create(['id' => (string) Str::uuid7(), 'name' => 'Ajena', 'code' => 'AJENA', 'status' => true]);
        $actor = $this->admin($a);

        try {
            app(TenantExecution::class)->run($a->id, $actor->id, Permission::PlantsView,
                fn () => app(AuditLogger::class)->record('test.cross', $plant));
            $this->fail('Debe rechazar organización ajena');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseMissing('audit_logs', ['action' => 'test.cross']);
        }
    }

    public function test_auditable_tenant_sin_organization_id_cargado_falla_cerrado(): void
    {
        $org = $this->organization('AUDIT-PARTIAL');
        app(TenantContext::class)->set($org->id);
        $plant = Plant::create(['id' => (string) Str::uuid7(), 'name' => 'Parcial', 'code' => 'PARTIAL', 'status' => true]);
        $partial = Plant::query()->select('id')->findOrFail($plant->id);
        $actor = $this->admin($org);
        try {
            app(TenantExecution::class)->run($org->id, $actor->id, Permission::PlantsView,
                fn () => app(AuditLogger::class)->record('test.partial', $partial));
            $this->fail('Debe fallar sin organización cargada');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseMissing('audit_logs', ['action' => 'test.partial']);
        }
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_t35_registro_en_la_organizacion_correcta(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        app(TenantContext::class)->set($orgA->id);
        $this->actingAs($user, 'sanctum');

        $auditable = $orgA;

        app(AuditLogger::class)->record('test.action', $auditable);

        $this->assertSame(1, AuditLog::where('organization_id', $orgA->id)->count());
        $this->assertSame(0, AuditLog::where('organization_id', $orgB->id)->count());

        app(TenantContext::class)->set($orgB->id);
        $this->actingAs($user, 'sanctum');

        $this->assertSame(0, AuditLog::where('organization_id', $orgA->id)->count());
    }

    public function test_t43_se_registra_aunque_la_membresia_del_actor_este_revocada(): void
    {
        $organization = $this->organization('ORG-REVOKED');
        $user = $this->user($organization);

        OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->update(['status' => OrganizationMembership::STATUS_REVOKED]);

        app(TenantContext::class)->set($organization->id);
        $this->actingAs($user, 'sanctum');

        $log = app(AuditLogger::class)->record('test.revoked', $organization);

        $this->assertNotNull($log->id);
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_sin_contexto_lanza_excepcion_y_no_deja_registros(): void
    {
        $organization = $this->organization('ORG-NOCTX');
        $user = $this->user($organization);

        app(TenantContext::class)->clear();
        $this->actingAs($user, 'sanctum');

        $this->expectException(\InvalidArgumentException::class);

        try {
            app(AuditLogger::class)->record('test.noctx', $organization);
        } catch (\Throwable $e) {
            $this->assertSame(0, AuditLog::count());

            throw $e;
        }
    }

    public function test_append_only_update_lanza_excepcion(): void
    {
        $organization = $this->organization('ORG-APPEND');
        $user = $this->user($organization);

        app(TenantContext::class)->set($organization->id);
        $this->actingAs($user, 'sanctum');

        $log = app(AuditLogger::class)->record('test.append', $organization);

        $this->expectException(AuditLogImmutableException::class);

        $log->action = 'changed';
        $log->save();
    }

    public function test_append_only_delete_lanza_excepcion(): void
    {
        $organization = $this->organization('ORG-APPEND2');
        $user = $this->user($organization);

        app(TenantContext::class)->set($organization->id);
        $this->actingAs($user, 'sanctum');

        $log = app(AuditLogger::class)->record('test.append', $organization);

        $this->expectException(AuditLogImmutableException::class);

        $log->delete();
    }

    public function test_redaccion_de_claves_sensibles_anidadas(): void
    {
        $organization = $this->organization('ORG-REDACT');
        $user = $this->user($organization);

        app(TenantContext::class)->set($organization->id);
        $this->actingAs($user, 'sanctum');

        $log = app(AuditLogger::class)->record('test.redact', $organization, [], [
            'password' => 'secret',
            'nested' => ['token' => 'abc', 'safe' => 'ok'],
        ]);

        $stored = $log->fresh()->new_values;

        $this->assertSame('[redacted]', $stored['password']);
        $this->assertSame('[redacted]', $stored['nested']['token']);
        $this->assertSame('ok', $stored['nested']['safe']);
    }
}
