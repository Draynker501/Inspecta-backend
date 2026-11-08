<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

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
        $host = $connection->getConfig('host');

        TestDatabaseGuard::assertSafe(
            $driver,
            $database,
            is_string($host) ? $host : null,
            $environment,
            $_ENV['TESTING_DB_ALLOW_EXTERNAL']
                ?? $_SERVER['TESTING_DB_ALLOW_EXTERNAL']
                ?? getenv('TESTING_DB_ALLOW_EXTERNAL')
                ?: null
        );
    }
}
