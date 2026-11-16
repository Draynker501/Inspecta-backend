<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$base = (string) getenv('APP_BASE_PATH');
require $base.'/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($base): void {
    foreach (['App\\' => '/app/', 'Tests\\' => '/tests/', 'Database\\Factories\\' => '/database/factories/', 'Database\\Seeders\\' => '/database/seeders/'] as $prefix => $path) {
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

$payload = json_decode(base64_decode((string) ($argv[2] ?? '')), true);
if (! is_array($payload)) {
    fwrite(STDERR, "payload invalido\n");
    exit(2);
}

$startFile = $payload['start_file'] ?? null;
if (is_string($startFile)) {
    $deadline = microtime(true) + 30;
    while (! file_exists($startFile) && microtime(true) < $deadline) {
        usleep(10000);
    }
}

try {
    $user = User::findOrFail($payload['user_id']);
    $token = $user->createToken('ehs-concurrency')->plainTextToken;
} catch (Throwable $exception) {
    echo json_encode(['status' => 500, 'body' => null, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}

$method = strtoupper((string) ($payload['method'] ?? 'GET'));
$uri = (string) ($payload['uri'] ?? '/');
$body = $payload['body'] ?? null;
$server = [
    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    'HTTP_X_ORGANIZATION_ID' => (string) $payload['organization_id'],
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
];
$content = is_array($body) ? json_encode($body) : null;
$request = Request::create($uri, $method, [], [], [], $server, $content);
try {
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    echo json_encode([
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true),
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['status' => 500, 'body' => null, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE).PHP_EOL;
}
