<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Crea la aplicación y verifica la conexión efectiva antes de que se
     * ejecute cualquier trait de pruebas (incluido RefreshDatabase, que
     * lanzaría un migrate:fresh sobre la conexión resultante).
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $this->assertSafeTestingConnection($app);

        return $app;
    }

    /**
     * Aborta la prueba si Laravel resolvió una conexión distinta de
     * SQLite en memoria durante el entorno de pruebas.
     *
     * Se inspecciona la conexión resuelta por el DatabaseManager (driver y
     * base de datos efectivos, ya con DB_URL aplicado), no solo las
     * variables de entorno.
     */
    private function assertSafeTestingConnection(Application $app): void
    {
        $connection = $app['db']->connection();
        $environment = $app->environment();
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        $problems = [];

        if ($environment !== 'testing') {
            $problems[] = "APP_ENV efectivo '{$environment}', se esperaba 'testing'";
        }

        if ($driver !== 'sqlite') {
            $problems[] = "driver efectivo '{$driver}', se esperaba 'sqlite'";
        }

        if ($database !== ':memory:') {
            $problems[] = "base de datos efectiva '{$database}', se esperaba ':memory:'";
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "Entorno de pruebas inseguro: se aborta antes de ejecutar migraciones o consultas.\n - "
                .implode("\n - ", $problems)
            );
        }
    }
}
