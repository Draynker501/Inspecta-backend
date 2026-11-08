<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Guardia pura para la conexión efectiva de pruebas.
 *
 * Extraída de Tests\TestCase para poder validarla de forma unitaria.
 * No depende de Laravel ni de la aplicación.
 */
final class TestDatabaseGuard
{
    /**
     * @throws RuntimeException si la conexión no es segura para pruebas.
     */
    public static function assertSafe(
        string $driver,
        ?string $database,
        ?string $host,
        string $appEnvironment,
        ?string $allowExternalFlag
    ): void {
        $database = $database ?? '';
        $host = $host ?? '';
        $allowExternalFlag = $allowExternalFlag ?? '';

        $externalAllowed = filter_var($allowExternalFlag, FILTER_VALIDATE_BOOLEAN);

        $problems = [];

        if ($appEnvironment !== 'testing') {
            $problems[] = "APP_ENV efectivo '{$appEnvironment}', se esperaba 'testing'";
        }

        if ($driver === 'sqlite') {
            if ($database !== ':memory:') {
                $problems[] = "base de datos efectiva '{$database}', se esperaba ':memory:'";
            }
        } elseif (in_array($driver, ['mariadb', 'mysql'], true)) {
            if (! $externalAllowed) {
                $problems[] = 'TESTING_DB_ALLOW_EXTERNAL debe ser "true" para usar MariaDB/MySQL en pruebas';
            }

            if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
                $problems[] = "host '{$host}' no está en la lista local permitida (127.0.0.1, localhost)";
            }

            if (! str_ends_with($database, '_test')) {
                $problems[] = "base de datos '{$database}' debe terminar en '_test'";
            }
        } else {
            $problems[] = "driver '{$driver}' no está permitido en pruebas";
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "Entorno de pruebas inseguro: se aborta antes de ejecutar migraciones o consultas.\n - "
                .implode("\n - ", $problems)
            );
        }
    }
}
