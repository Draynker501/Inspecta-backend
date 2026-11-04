<?php

namespace Tests\Feature\Audit;

use App\Exceptions\AuditLogImmutableException;
use App\Models\AuditLog;
use App\Models\OrganizationMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

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
