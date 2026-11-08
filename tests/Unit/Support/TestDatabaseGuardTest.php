<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    public function test_sqlite_memory_permitido(): void
    {
        TestDatabaseGuard::assertSafe('sqlite', ':memory:', null, 'testing', null);
        $this->assertTrue(true);
    }

    public function test_sqlite_archivo_rechazado(): void
    {
        $this->expectException(\RuntimeException::class);
        TestDatabaseGuard::assertSafe('sqlite', '/tmp/test.sqlite', null, 'testing', null);
    }

    public function test_mariadb_condiciones_completas_permitido(): void
    {
        TestDatabaseGuard::assertSafe('mariadb', 'inspecta_test', '127.0.0.1', 'testing', 'true');
        $this->assertTrue(true);
    }

    public function test_mariadb_sin_flag_rechazado(): void
    {
        $this->expectException(\RuntimeException::class);
        TestDatabaseGuard::assertSafe('mariadb', 'inspecta_test', '127.0.0.1', 'testing', null);
    }

    public function test_host_remoto_rechazado(): void
    {
        $this->expectException(\RuntimeException::class);
        TestDatabaseGuard::assertSafe('mariadb', 'inspecta_test', '10.0.0.1', 'testing', 'true');
    }

    public function test_base_sin_sufijo_test_rechazada(): void
    {
        $this->expectException(\RuntimeException::class);
        TestDatabaseGuard::assertSafe('mariadb', 'inspecta', '127.0.0.1', 'testing', 'true');
    }

    public function test_app_env_distinto_testing_rechazado(): void
    {
        $this->expectException(\RuntimeException::class);
        TestDatabaseGuard::assertSafe('mariadb', 'inspecta_test', '127.0.0.1', 'local', 'true');
    }

    public function test_driver_pgsql_rechazado(): void
    {
        $this->expectException(\RuntimeException::class);
        TestDatabaseGuard::assertSafe('pgsql', 'inspecta_test', '127.0.0.1', 'testing', 'true');
    }
}
