<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

$base = (string) getenv('APP_BASE_PATH');
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
TestDatabaseGuard::assertSafe(
    $connection->getDriverName(),
    $connection->getDatabaseName(),
    $connection->getConfig('host'),
    app()->environment(),
    getenv('TESTING_DB_ALLOW_EXTERNAL') ?: null
);
$payload = json_decode(base64_decode((string) ($argv[2] ?? '')), true);
if (! is_array($payload) || ! in_array($payload['table'] ?? null, ['plants', 'areas', 'production_lines'], true)) {
    exit(2);
}
$deadline = microtime(true) + 30;
while (! file_exists($payload['start_file']) && microtime(true) < $deadline) {
    usleep(10000);
}
try {
    $updated = DB::table($payload['table'])
        ->where('organization_id', $payload['organization_id'])
        ->where('id', $payload['id'])
        ->update(['status' => 0]);
    echo json_encode(['status' => $updated === 1 ? 200 : 404]).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['status' => 500, 'error' => $exception->getMessage()]).PHP_EOL;
}
