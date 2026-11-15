<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    foreach ($input['environment'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connection = $app['db']->connection();
    TestDatabaseGuard::assertSafe(
        $connection->getDriverName(),
        $connection->getDatabaseName(),
        $connection->getConfig('host'),
        $app->environment(),
        getenv('TESTING_DB_ALLOW_EXTERNAL') ?: null
    );
    if ($connection->getDriverName() !== 'mariadb') {
        throw new RuntimeException('Worker requiere MariaDB.');
    }
    $connection->statement('SET SESSION innodb_lock_wait_timeout=12');
    $id = (int) $connection->scalar('SELECT CONNECTION_ID()');

    // Señal de conexión para los escenarios que observan la contención.
    $connection->beforeExecuting(function ($sql) use ($input, $id): void {
        $sql = strtolower($sql);
        $isLock = str_contains($sql, 'for update') && str_contains($sql, 'plants');
        $isUpdate = ($input['operation'] ?? 'http') !== 'http'
            && (str_starts_with($sql, 'update `plants`') || str_starts_with($sql, 'update `areas`'));
        if ($isLock || $isUpdate) {
            file_put_contents($input['signal'], json_encode(['connection_id' => $id], JSON_THROW_ON_ERROR), LOCK_EX);
        }
    });

    if (($input['operation'] ?? 'http') !== 'http') {
        $values = $input['operation'] === 'deactivate' ? ['status' => false] : ['deleted_at' => date('Y-m-d H:i:s')];
        $target = $input['target'] ?? 'plant';
        $table = $target === 'area' ? 'areas' : 'plants';
        $rowId = $target === 'area' ? $input['area_id'] : $input['plant_id'];
        $connection->table($table)->where('id', $rowId)->update($values);
        echo json_encode(['status' => 'updated'], JSON_THROW_ON_ERROR);
        exit(0);
    }

    // Bloqueo de la última adquisición antes de responder, para escenarios
    // en los que el worker debe mantener la transacción abierta.
    if (isset($input['locked'], $input['release'])) {
        $needle = $input['lock_signal_table'] ?? 'plants';
        $connection->listen(function ($event) use ($input, $needle): void {
            $sql = strtolower($event->sql);
            if (! str_contains($sql, 'for update') || ! str_contains($sql, $needle)) {
                return;
            }
            file_put_contents($input['locked'], 'locked', LOCK_EX);
            $deadline = microtime(true) + 12;
            while (! is_file($input['release'])) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timeout esperando liberación del worker HTTP.');
                }
                usleep(20000);
            }
        });
    }

    $route = $input['route'] ?? 'area';
    $path = $route === 'production_line'
        ? '/api/areas/'.$input['area_id'].'/lines'
        : '/api/plants/'.$input['plant_id'].'/areas';
    $body = $route === 'production_line'
        ? ['name' => 'Línea concurrente', 'code' => $input['code'] ?? 'CONC-01']
        : ['name' => 'Área concurrente', 'code' => $input['code'] ?? 'CONC-02'];

    $request = Request::create($path, 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
        'HTTP_X_ORGANIZATION_ID' => $input['organization_id'],
    ], json_encode($body, JSON_THROW_ON_ERROR));

    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
