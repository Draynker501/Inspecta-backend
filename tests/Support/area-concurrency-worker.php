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
    $connection->beforeExecuting(function ($sql) use ($input, $id): void {
        $sql = strtolower($sql);
        if ((str_contains($sql, 'for update') && str_contains($sql, 'plants'))
            || (($input['operation'] ?? 'http') !== 'http' && str_starts_with($sql, 'update `plants`'))) {
            file_put_contents($input['signal'], json_encode(['connection_id' => $id], JSON_THROW_ON_ERROR), LOCK_EX);
        }
    });
    if (($input['operation'] ?? 'http') !== 'http') {
        $values = $input['operation'] === 'deactivate' ? ['status' => false] : ['deleted_at' => date('Y-m-d H:i:s')];
        $connection->table('plants')->where('id', $input['plant_id'])->update($values);
        echo json_encode(['status' => 'updated'], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if (isset($input['locked'], $input['release'])) {
        $connection->listen(function ($event) use ($input): void {
            $sql = strtolower($event->sql);
            if (! str_contains($sql, 'for update') || ! str_contains($sql, 'plants')) {
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
    $request = Request::create('/api/plants/'.$input['plant_id'].'/areas', 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
        'HTTP_X_ORGANIZATION_ID' => $input['organization_id'],
    ], json_encode(['name' => 'Área concurrente', 'code' => $input['code'] ?? 'CONC-02'], JSON_THROW_ON_ERROR));
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
