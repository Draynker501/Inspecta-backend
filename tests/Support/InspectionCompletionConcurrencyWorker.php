<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$base = (string) getenv('APP_BASE_PATH');
require $base.'/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($base): void {
    foreach (['App\\' => '/app/', 'Tests\\' => '/tests/'] as $prefix => $path) {
        if (str_starts_with($class, $prefix)) {
            $file = $base.$path.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
}, true, true);

$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$payload = json_decode(base64_decode((string) ($argv[1] ?? '')), true, 512, JSON_THROW_ON_ERROR);

try {
    $user = User::findOrFail($payload['user_id']);
    $token = $user->createToken('ins03-concurrency')->plainTextToken;
    if (isset($payload['hold_marker'], $payload['release_marker'])) {
        DB::listen(static function ($event) use ($payload): void {
            if (preg_match('/\bfrom [`"]?inspections[`"]?.*\bfor update\b/is', $event->sql) !== 1) {
                return;
            }
            file_put_contents($payload['hold_marker'], 'locked');
            $deadline = microtime(true) + 20;
            while (! file_exists($payload['release_marker']) && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (! file_exists($payload['release_marker'])) {
                throw new RuntimeException('No se liberó la barrera de prueba.');
            }
        });
    }

    $request = Request::create(
        $payload['uri'],
        $payload['method'],
        [], [], [],
        [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_ORGANIZATION_ID' => $payload['organization_id'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $payload['method'] === 'DELETE' ? null : ($payload['method'] === 'POST' ? '{}' : json_encode($payload['body'], JSON_THROW_ON_ERROR)),
    );
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['status' => 500, 'error' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL;
}
