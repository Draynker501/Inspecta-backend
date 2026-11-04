<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\UserRole;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class AuditedAssignmentsTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_nueva_asignacion_de_rol_crea_un_registro_de_auditoria(): void
    {
        $organization = $this->organization('ORG-AUDIT');
        $user = $this->user($organization);

        app(TenantContext::class)->set($organization->id);

        $role = $this->role($organization, 'Inspector');

        $user->assignRole($role);

        $this->assertSame(1, AuditLog::where('action', 'role.assigned')->count());
    }

    public function test_repetir_asignacion_de_rol_no_crea_auditoria(): void
    {
        $organization = $this->organization('ORG-AUDIT2');
        $user = $this->user($organization);

        app(TenantContext::class)->set($organization->id);

        $role = $this->role($organization, 'Inspector');

        $user->assignRole($role);
        $user->assignRole($role);

        $this->assertSame(1, AuditLog::where('action', 'role.assigned')->count());
    }

    public function test_si_el_logger_falla_la_asignacion_no_queda_guardada(): void
    {
        $organization = $this->organization('ORG-AUDIT3');
        $user = $this->user($organization);

        app(TenantContext::class)->set($organization->id);

        $role = $this->role($organization, 'Inspector');

        app()->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, array $oldValues = [], array $newValues = []): AuditLog
            {
                throw new \RuntimeException('logger fail');
            }
        });

        try {
            $user->assignRole($role);
            $this->fail('Se esperaba excepción.');
        } catch (\RuntimeException $e) {
            $this->assertSame('logger fail', $e->getMessage());
        }

        $this->assertSame(0, UserRole::where('user_id', $user->id)->where('role_id', $role->id)->count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_nueva_asignacion_de_planta_crea_un_registro_de_auditoria(): void
    {
        $organization = $this->organization('ORG-AUDIT-PLANT');
        $user = $this->user($organization);

        app(TenantContext::class)->set($organization->id);

        $plant = $this->plant($organization, 'P-01');

        $user->assignPlant($plant);
        $user->assignPlant($plant);

        $this->assertSame(1, AuditLog::where('action', 'plant.assigned')->count());
    }

    public function test_nueva_asignacion_de_area_crea_un_registro_de_auditoria(): void
    {
        $organization = $this->organization('ORG-AUDIT-AREA');
        $user = $this->user($organization);

        app(TenantContext::class)->set($organization->id);

        $plant = $this->plant($organization, 'P-01');
        $area = $this->area($plant, 'A-01');

        $user->assignArea($area);
        $user->assignArea($area);

        $this->assertSame(1, AuditLog::where('action', 'area.assigned')->count());
    }
}
