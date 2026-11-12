<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\Permission;
use App\Console\Commands\TenantCommand;
use App\Exceptions\InvalidTenantOrganizationException;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Plant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantExecution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class TenantExecutionTest extends TestCase
{
    use RefreshDatabase, TenantFixtures;

    public function test_frontera_no_acepta_resolvedor_de_actor_ejecutable(): void
    {
        $org = $this->clientOrganization('CALLBACK');
        $called = false;
        try {
            app(TenantExecution::class)->run($org->id, function () use (&$called) {
                $called = true;

                return null;
            }, Permission::PlantsView, fn () => null);
            $this->fail('Debe rechazar callbacks como identidad');
        } catch (\TypeError) {
            $this->assertFalse($called);
            $this->assertSame(0, Plant::withoutGlobalScopes()->count());
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_sistema_sin_actor_se_rechaza_aun_con_autorizacion_afirmativa(): void
    {
        $org = $this->clientOrganization('NO-ACTOR');
        $this->expectException(AuthorizationException::class);
        app(TenantExecution::class)->run($org->id, null, Permission::PlantsView, fn () => $this->fail('No debe ejecutarse'));
    }

    public function test_fronteras_secuenciales_y_anidadas_no_heredan_estado(): void
    {
        $a = $this->clientOrganization('A');
        $b = $this->clientOrganization('B');
        $ua = $this->admin($a);
        $ub = $this->admin($b);
        $context = app(TenantContext::class);
        $execution = app(TenantExecution::class);
        $context->set($b->id);
        $context->setPermissionCache('u', $a->id, ['stale']);

        $this->executeAs($a->id, $ua, function () use ($context, $a, $b, $ub): void {
            $this->assertSame($a->id, $context->id());
            $this->assertNull($context->getPermissionCache('u', $a->id));
            $this->executeAs($b->id, $ub, function () use ($context, $b): void {
                $this->assertSame($b->id, $context->id());
            });
            $this->assertSame($a->id, $context->id());
        });
        $this->assertNull($context->id());
        $this->executeAs($b->id, $ub, fn () => $this->assertSame($b->id, $context->id()));
        $this->assertNull($context->id());
    }

    public function test_organizacion_invalida_no_ejecuta_operacion_y_limpia_contexto(): void
    {
        $context = app(TenantContext::class);
        $org = $this->clientOrganization('A');
        $context->set($org->id);
        $called = false;
        try {
            app(TenantExecution::class)->run('', null, Permission::PlantsView, function () use (&$called): void {
                $called = true;
            });
            $this->fail('Debe fallar');
        } catch (\InvalidArgumentException) {
            $this->assertFalse($called);
            $this->assertNull($context->id());
        }
    }

    public function test_usuario_inactivo_no_puede_autorizar(): void
    {
        $org = $this->clientOrganization('A');
        $user = $this->user($org);
        $user->status = false;
        $user->save();
        $this->expectException(AuthorizationException::class);
        app(TenantExecution::class)->run($org->id, $user->id, Permission::PlantsView, fn () => null);
    }

    public function test_t20_y_t21_job_con_y_sin_organizacion(): void
    {
        $a = $this->clientOrganization('A');
        $b = $this->clientOrganization('B');
        $job = new TenantTestJob;
        $job->organizationId = $a->id;
        $job->actorId = $this->admin($a)->id;
        $job->handle();
        $this->assertDatabaseHas('plants', ['code' => 'JOB', 'organization_id' => $a->id]);
        $this->assertDatabaseMissing('plants', ['code' => 'JOB', 'organization_id' => $b->id]);
        $this->assertNull(app(TenantContext::class)->id());

        app(TenantContext::class)->set($b->id);
        try {
            (new TenantTestJob)->handle();
            $this->fail('Se esperaba rechazo del payload vacío');
        } catch (\InvalidArgumentException) {
            $this->assertSame(1, Plant::withoutGlobalScopes()->count());
            $this->assertNull(app(TenantContext::class)->id());
        }
    }

    public function test_job_y_comando_sin_permiso_no_escriben(): void
    {
        $org = $this->clientOrganization('DENIED');
        $actor = $this->user($org);
        $job = new TenantTestJob;
        $job->organizationId = $org->id;
        $job->actorId = $actor->id;
        try {
            $job->handle();
            $this->fail('Job denegado');
        } catch (AuthorizationException) {
            $this->assertSame(0, Plant::withoutGlobalScopes()->count());
            $this->assertDatabaseCount('audit_logs', 0);
        }

        $command = new TenantTestCommand;
        $command->tenantActorId = $actor->id;
        app(Kernel::class)->registerCommand($command);
        try {
            $this->artisan('test:tenant-command', ['--organization' => $org->id])->run();
            $this->fail('Comando denegado');
        } catch (AuthorizationException) {
            $this->assertSame(0, Plant::withoutGlobalScopes()->count());
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertNull(app(TenantContext::class)->id());
        }
    }

    public function test_excepcion_en_transaccion_del_job_revierte_escrituras_y_auditoria(): void
    {
        $org = $this->clientOrganization('ROLLBACK');
        $job = new TenantTestJob;
        $job->organizationId = $org->id;
        $job->actorId = $this->admin($org)->id;
        $before = DB::table('audit_logs')->count();
        $job->failInTransaction = true;
        try {
            $job->handle();
            $this->fail('Debe fallar');
        } catch (\RuntimeException) {
            $this->assertDatabaseMissing('plants', ['code' => 'JOB']);
            $this->assertSame($before, DB::table('audit_logs')->count());
            $this->assertNull(app(TenantContext::class)->id());
        }
    }

    public function test_job_rechaza_organizaciones_no_validas_sin_escrituras(): void
    {
        $inactive = $this->clientOrganization('INACTIVE', ['status' => false]);
        $operator = $this->operatorOrganization();
        $deleted = $this->clientOrganization('DELETED');
        $deleted->delete();
        foreach (['no-uuid', (string) Str::uuid(), $inactive->id, $operator->id, $deleted->id] as $id) {
            $job = new TenantTestJob;
            $job->organizationId = $id;
            try {
                $job->handle();
                $this->fail('Organización inválida');
            } catch (\InvalidArgumentException) {
                $this->assertSame(0, Plant::withoutGlobalScopes()->count());
                $this->assertNull(app(TenantContext::class)->id());
            }
        }
    }

    public function test_organizacion_inactiva_se_rechaza_antes_de_consultar_o_autorizar_al_actor(): void
    {
        $organization = $this->clientOrganization('INACTIVE-DIRECT');
        $actor = $this->admin($organization);
        $this->assertTrue($actor->hasPermission(Permission::PlantsManage));
        $organization->status = false;
        $organization->save();
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => false]);
        $auditCount = DB::table('audit_logs')->count();

        $actorQueried = false;
        DB::listen(function ($query) use (&$actorQueried): void {
            if (preg_match('/\bfrom\s+["`\[]?users\b/i', $query->sql)) {
                $actorQueried = true;
            }
        });

        $operationCalled = false;
        try {
            app(TenantExecution::class)->run($organization->id, $actor->id, Permission::PlantsManage, function () use (&$operationCalled): void {
                $operationCalled = true;
            });
            $this->fail('Debe rechazar la organización inactiva en su validación');
        } catch (InvalidTenantOrganizationException $exception) {
            $this->assertSame('La organización debe ser un cliente activo existente.', $exception->getMessage());
        }

        $this->assertFalse($actorQueried, 'No debe consultar ni autorizar al actor');
        $this->assertFalse($operationCalled);
        $this->assertSame(0, Plant::withoutGlobalScopes()->count());
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_t22_comando_valida_opcion_y_ejecuta_solo_cliente_activo(): void
    {
        $valid = $this->clientOrganization('A');
        $command = new TenantTestCommand;
        $command->tenantActorId = $this->admin($valid)->id;
        app(Kernel::class)->registerCommand($command);
        $inactive = $this->clientOrganization('B', ['status' => false]);
        $operator = $this->operatorOrganization();
        $deleted = $this->clientOrganization('C');
        $deleted->delete();
        foreach ([null, 'incorrecto', (string) Str::uuid(), $inactive->id, $operator->id, $deleted->id] as $id) {
            $args = $id === null ? [] : ['--organization' => $id];
            $this->artisan('test:tenant-command', $args)->assertExitCode(1);
            $this->assertSame(0, Plant::withoutGlobalScopes()->count());
            $this->assertNull(app(TenantContext::class)->id());
        }
        $this->artisan('test:tenant-command', ['--organization' => $valid->id])->assertExitCode(0);
        $this->assertDatabaseHas('plants', ['code' => 'COMMAND', 'organization_id' => $valid->id]);
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_excepcion_anidada_restauracion_y_reintento(): void
    {
        $a = $this->clientOrganization('A');
        $b = $this->clientOrganization('B');
        $ua = $this->admin($a);
        $ub = $this->admin($b);
        $context = app(TenantContext::class);
        $execute = app(TenantExecution::class);
        $this->executeAs($a->id, $ua, function () use ($context, $a, $b, $ub): void {
            try {
                $this->executeAs($b->id, $ub, fn () => throw new \RuntimeException('error'));
            } catch (\RuntimeException) {
                $this->assertSame($a->id, $context->id());
            }
        });
        $this->assertNull($context->id());
        $this->executeAs($b->id, $ub, fn () => $this->assertSame($b->id, $context->id()));
    }

    public function test_comando_con_excepcion_limpia_contexto(): void
    {
        $a = $this->clientOrganization('A');
        $command = new ThrowingTenantTestCommand;
        $command->tenantActorId = $this->admin($a)->id;
        app(Kernel::class)->registerCommand($command);
        try {
            $this->artisan('test:tenant-throws', ['--organization' => $a->id])->run();
            $this->fail('Debe propagarse excepción');
        } catch (\RuntimeException) {
            $this->assertNull(app(TenantContext::class)->id());
        }
    }

    public function test_bypass_anidado_se_aisla_y_restaura_y_bypass_externo_se_rechaza(): void
    {
        $a = $this->clientOrganization('A');
        $b = $this->clientOrganization('B');
        $ua = $this->admin($a);
        $ub = $this->admin($b);
        $context = app(TenantContext::class);
        $execute = app(TenantExecution::class);
        $this->executeAs($a->id, $ua, function () use ($context, $b, $ub): void {
            $context->withoutTenant('test', function () use ($context, $b, $ub): void {
                $this->executeAs($b->id, $ub, function () use ($context): void {
                    $this->assertFalse($context->isBypassingTenant());
                });
                $this->assertTrue($context->isBypassingTenant());
            });
        });
        $this->assertFalse($context->isBypassingTenant());
        $context->withoutTenant('test', function () use ($execute, $a): void {
            $this->expectException(\LogicException::class);
            $execute->run($a->id, null, Permission::PlantsView, fn () => null);
        });
    }

    public function test_auth_ambiente_no_es_identidad_y_no_se_modifica(): void
    {
        $a = $this->clientOrganization('A');
        $user = $this->admin($a);
        $ambient = $this->user($a);
        Auth::guard()->setUser($ambient);
        $execute = app(TenantExecution::class);
        $guard = Auth::guard();
        $this->executeAs($a->id, $user, function ($actor) use ($user, $ambient): void {
            $this->assertSame($user->id, $actor->id);
            $this->assertSame($ambient, Auth::user());
        });
        $this->assertSame($guard, Auth::guard());
        $this->assertSame($ambient, Auth::user());
    }

    public function test_autorizacion_explicita_y_membresia_revocada(): void
    {
        $a = $this->clientOrganization('A');
        $user = $this->user($a);
        $execute = app(TenantExecution::class);
        try {
            $execute->run($a->id, $user->id, Permission::PlantsView, fn () => $this->fail('Sin autorización'));
            $this->fail('Se esperaba denegación');
        } catch (AuthorizationException) {
            $this->assertNull(app(TenantContext::class)->id());
        }
        $user->memberships()->update(['status' => 'revoked']);
        $this->expectException(AuthorizationException::class);
        $execute->run($a->id, $user->id, Permission::PlantsView, fn () => $this->fail('Membresía revocada'));
    }

    public function test_actor_sin_permiso_se_rechaza(): void
    {
        $a = $this->clientOrganization('A');
        $user = $this->user($a);
        $this->expectException(AuthorizationException::class);
        app(TenantExecution::class)->run($a->id, $user->id, Permission::PlantsView, fn () => $this->fail('Sin autenticación'));
    }

    public function test_fibra_se_rechaza(): void
    {
        $a = $this->clientOrganization('A');
        $fiber = new \Fiber(fn () => app(TenantExecution::class)->run($a->id, null, Permission::PlantsView, fn () => null));
        $this->expectException(\LogicException::class);
        $fiber->start();
    }

    public function test_permiso_cambiado_entre_ejecuciones_no_reutiliza_cache(): void
    {
        $a = $this->clientOrganization('A');
        $user = $this->admin($a);
        $execute = app(TenantExecution::class);
        $execute->run($a->id, $user->id, Permission::PlantsView, fn () => $this->assertTrue(true));
        DB::table('role_permissions')->where('organization_id', $a->id)->where('permission', Permission::PlantsView->value)->delete();
        $this->expectException(AuthorizationException::class);
        $execute->run($a->id, $user->id, Permission::PlantsView, fn () => $this->fail('Permiso revocado'));
    }

    public function test_usuario_desactivado_entre_ejecuciones_se_rechaza(): void
    {
        $org = $this->clientOrganization('ACTOR');
        $actor = $this->admin($org);
        $this->executeAs($org->id, $actor, fn () => $this->assertTrue(true));
        $actor->status = false;
        $actor->save();
        $this->assertDatabaseHas('users', ['id' => $actor->id, 'status' => false]);
        $this->expectException(AuthorizationException::class);
        $this->executeAs($org->id, $actor, fn () => $this->fail('Actor inactivo'));
    }

    public function test_request_resolver_ambiental_no_aporta_actor_y_se_conserva(): void
    {
        $a = $this->clientOrganization('A');
        $user = $this->admin($a);
        request()->setUserResolver(fn () => $user);
        $this->executeAs($a->id, $user, fn () => $this->assertSame($user, request()->user()));
        $this->assertNull(app(TenantContext::class)->id());
        $this->assertSame($user, request()->user());
    }

    public function test_auditoria_explicita_anidamiento_excepcion_y_restauracion(): void
    {
        $org = $this->clientOrganization('AUDIT');
        $actor = $this->admin($org);
        $inner = $this->user($org);
        $inner->assignRole($actor->roles()->firstOrFail());
        $ambient = $this->user($org);
        Auth::setUser($ambient);
        $execution = app(TenantExecution::class);
        $log = function (string $action) use ($org): void {
            app(AuditLogger::class)->record($action, $org);
        };

        $execution->run($org->id, $actor->id, Permission::PlantsView, function () use ($org, $log, $inner): void {
            $log('outer.before');
            try {
                $this->executeAs($org->id, $inner, function () use ($log): void {
                    $log('inner.actor');
                    throw new \RuntimeException('test');
                });
            } catch (\RuntimeException) {
                $log('outer.after');
            }
        });
        app(TenantContext::class)->runAs($org->id, fn () => $log('outside'));
        foreach (['outer.before' => $actor->id, 'inner.actor' => $inner->id, 'outer.after' => $actor->id, 'outside' => $ambient->id] as $action => $id) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'user_id' => $id]);
        }
        $this->assertSame($ambient, Auth::user());
    }

    private function executeAs(string $orgId, User $actor, \Closure $operation): mixed
    {
        return app(TenantExecution::class)->run($orgId, $actor->id,
            Permission::PlantsView, $operation);
    }
}

class TenantTestJob
{
    use TenantAwareJob;

    public bool $failInTransaction = false;

    protected function tenantPermission(): Permission
    {
        return Permission::PlantsManage;
    }

    protected function handleTenant(?User $actor): mixed
    {
        if ($this->failInTransaction) {
            return DB::transaction(function () {
                $plant = Plant::create(['id' => (string) Str::uuid7(), 'name' => 'Job', 'code' => 'JOB', 'status' => true]);
                app(AuditLogger::class)->record('job.test', $plant);
                throw new \RuntimeException('rollback');
            });
        }

        return Plant::create(['id' => (string) Str::uuid7(), 'name' => 'Job', 'code' => 'JOB', 'status' => true]);
    }
}

class TenantTestCommand extends TenantCommand
{
    protected $signature = 'test:tenant-command';

    protected function tenantPermission(): Permission
    {
        return Permission::PlantsManage;
    }

    protected function handleTenant(?User $actor): int
    {
        Plant::create(['id' => (string) Str::uuid7(), 'name' => 'Command', 'code' => 'COMMAND', 'status' => true]);

        return self::SUCCESS;
    }
}

class ThrowingTenantTestCommand extends TenantCommand
{
    protected $signature = 'test:tenant-throws';

    protected function tenantPermission(): Permission
    {
        return Permission::PlantsView;
    }

    protected function handleTenant(?User $actor): int
    {
        throw new \RuntimeException('test');
    }
}
