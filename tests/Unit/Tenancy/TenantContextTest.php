<?php

namespace Tests\Unit\Tenancy;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_establecer_y_leer_contexto(): void
    {
        $context = app(TenantContext::class);
        $id = (string) Str::uuid7();

        $context->set($id);

        $this->assertTrue($context->has());
        $this->assertSame($id, $context->id());
        $this->assertSame($id, $context->requireId());
    }

    public function test_require_id_sin_contexto_lanza_excepcion(): void
    {
        $context = app(TenantContext::class);
        $context->clear();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No hay contexto organizacional establecido.');

        $context->requireId();
    }

    public function test_limpiar_contexto(): void
    {
        $context = app(TenantContext::class);
        $context->set((string) Str::uuid7());

        $this->assertTrue($context->has());

        $context->clear();

        $this->assertFalse($context->has());
        $this->assertNull($context->id());
    }

    public function test_run_as_restaura_contexto_anterior(): void
    {
        $context = app(TenantContext::class);
        $idA = (string) Str::uuid7();
        $idB = (string) Str::uuid7();

        $context->set($idA);

        $context->runAs($idB, function () use ($idB, $context) {
            $this->assertSame($idB, $context->id());
            $this->assertTrue($context->has());
        });

        $this->assertSame($idA, $context->id());
    }

    public function test_run_as_restaura_contexto_ante_excepcion(): void
    {
        $context = app(TenantContext::class);
        $idA = (string) Str::uuid7();
        $idB = (string) Str::uuid7();

        $context->set($idA);

        try {
            $context->runAs($idB, function () {
                throw new \RuntimeException('error simulado');
            });
            $this->fail('Se esperaba una excepción.');
        } catch (\RuntimeException $e) {
            $this->assertSame('error simulado', $e->getMessage());
        }

        $this->assertSame($idA, $context->id());
    }

    public function test_without_tenant_requiere_motivo(): void
    {
        $context = app(TenantContext::class);
        $context->set((string) Str::uuid7());

        $this->expectException(\TypeError::class);

        $context->withoutTenant(fn () => null);
    }

    public function test_without_tenant_ejecuta_y_restaura(): void
    {
        $context = app(TenantContext::class);
        $idA = (string) Str::uuid7();

        $context->set($idA);

        $result = $context->withoutTenant('diagnóstico de consola', function () use ($context) {
            $this->assertFalse($context->has());
            $this->assertNull($context->id());

            return 'resultado';
        });

        $this->assertSame('resultado', $result);
        $this->assertSame($idA, $context->id());
        $this->assertTrue($context->has());
    }

    public function test_no_hay_contaminacion_entre_ejecuciones(): void
    {
        $context = app(TenantContext::class);
        $idA = (string) Str::uuid7();
        $idB = (string) Str::uuid7();

        $context->set($idA);
        $context->clear();

        $this->assertFalse($context->has());
        $this->assertNull($context->id());

        $context->set($idB);
        $this->assertSame($idB, $context->id());

        $context->clear();
        $this->assertFalse($context->has());
    }

    public function test_set_rechaza_valor_no_uuid(): void
    {
        $context = app(TenantContext::class);

        $this->expectException(InvalidArgumentException::class);

        $context->set('no-es-un-uuid');
    }

    public function test_set_rechaza_cadena_vacia(): void
    {
        $context = app(TenantContext::class);

        $this->expectException(InvalidArgumentException::class);

        $context->set('');
    }
}
