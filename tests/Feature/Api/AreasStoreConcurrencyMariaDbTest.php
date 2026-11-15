<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * Exclusivamente MariaDB efímera: migrate:fresh destruye la base de pruebas.
 * Requiere PROCESS para observar INNODB_LOCK_WAITS; no usa RefreshDatabase.
 */
class AreasStoreConcurrencyMariaDbTest extends TestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $db = DB::connection();
        if ($db->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Requiere MariaDB efímera.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertContains($db->getConfig('host'), ['127.0.0.1', 'localhost']);
        $this->assertStringEndsWith('_test', $db->getDatabaseName());
        $this->assertTrue(filter_var(getenv('TESTING_DB_ALLOW_EXTERNAL'), FILTER_VALIDATE_BOOLEAN));
        $this->assertMatchesRegularExpression('/^11\\.8\\.9.*MariaDB/i', (string) $db->scalar('SELECT VERSION()'));
        $this->assertSame(1, (int) $db->scalar('SELECT @@foreign_key_checks'));
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_conc_02_desactivacion_confirmada_antes_del_alta(): void
    {
        $org = $this->clientOrganization('CONC02');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $token = $user->createToken('concurrency')->plainTextToken;
        $db = DB::connection();
        $config = $db->getConfig();
        config(['database.connections.concurrency_observer' => $config]);
        $observer = DB::connection('concurrency_observer');
        $signal = tempnam(is_dir('/tmp/opencode') ? '/tmp/opencode' : sys_get_temp_dir(), 'area-conc-');
        $this->assertIsString($signal);
        unlink($signal);

        $env = [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mariadb',
            'DB_HOST' => (string) $config['host'],
            'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => (string) $config['database'],
            'DB_USERNAME' => (string) $config['username'],
            'DB_PASSWORD' => (string) $config['password'],
            'DB_URL' => '',
            'TESTING_DB_ALLOW_EXTERNAL' => 'true',
            'TENANCY_STRICT_MODE' => 'true',
            'TENANCY_ALLOW_CONSOLE_WITHOUT_TENANT' => 'true',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];
        $process = new Process([PHP_BINARY, base_path('tests/Support/area-concurrency-worker.php')], base_path());
        $process->setTimeout(20);
        $process->setInput(json_encode([
            'environment' => $env, 'plant_id' => $plant->id, 'organization_id' => $org->id,
            'token' => $token, 'signal' => $signal,
        ], JSON_THROW_ON_ERROR));

        try {
            $db->beginTransaction();
            $db->table('plants')->where('id', $plant->id)->update(['status' => false]);
            $blockingId = (int) $db->scalar('SELECT CONNECTION_ID()');
            $process->start();
            $deadline = microtime(true) + 10;
            while (! is_file($signal) && microtime(true) < $deadline && $process->isRunning()) {
                usleep(20000);
            }
            $this->assertFileExists($signal, 'El worker no intentó bloquear la planta: '.$process->getErrorOutput());
            $workerId = json_decode(file_get_contents($signal), true, 512, JSON_THROW_ON_ERROR)['connection_id'];
            $waiting = false;
            while (microtime(true) < $deadline && $process->isRunning()) {
                $waiting = (bool) $observer->selectOne(
                    'SELECT 1 FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id = w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_trx_id WHERE r.trx_mysql_thread_id = ? AND b.trx_mysql_thread_id = ?',
                    [$workerId, $blockingId]
                );
                if ($waiting) {
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($waiting, 'No se observó la espera del worker sobre la transacción de desactivación.');
            $db->commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(422, $result['status']);
            $this->assertSame('validation_failed', $result['body']['code']);
            $this->assertArrayHasKey('plantId', $result['body']['errors']);
            $this->assertSame(0, $db->table('areas')->count());
            $this->assertSame(0, $db->table('audit_logs')->where('action', 'area.created')->count());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }
            if (is_file($signal)) {
                unlink($signal);
            }
            DB::purge('concurrency_observer');
        }
    }

    public function test_conc_01_dos_altas_del_mismo_codigo(): void
    {
        $this->runHttpFirst('http');
    }

    public function test_conc_03_alta_antes_de_desactivacion(): void
    {
        $this->runHttpFirst('deactivate');
    }

    public function test_conc_05_alta_antes_de_soft_delete(): void
    {
        $this->runHttpFirst('delete');
    }

    public function test_conc_04_soft_delete_antes_del_alta(): void
    {
        $this->runDeleteFirst();
    }

    private function concurrencyEnvironment(array $config): array
    {
        return [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mariadb', 'DB_HOST' => (string) $config['host'],
            'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => (string) $config['database'],
            'DB_USERNAME' => (string) $config['username'], 'DB_PASSWORD' => (string) $config['password'],
            'DB_URL' => '', 'TESTING_DB_ALLOW_EXTERNAL' => 'true', 'TENANCY_STRICT_MODE' => 'true',
            'TENANCY_ALLOW_CONSOLE_WITHOUT_TENANT' => 'true', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ];
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/area-concurrency-worker.php')], base_path());
        $process->setTimeout(20);
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->start();

        return $process;
    }

    private function awaitSignal(string $file, Process $process): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($file) && microtime(true) < $deadline && $process->isRunning()) {
            usleep(20000);
        }
        $this->assertFileExists($file, 'Falta señal del worker: '.$process->getErrorOutput());
    }

    private function awaitLock(int $requester, int $blocker, Process $process): void
    {
        $observer = DB::connection('concurrency_observer');
        $deadline = microtime(true) + 10;
        $waiting = false;
        while (microtime(true) < $deadline && $process->isRunning()) {
            $waiting = (bool) $observer->selectOne(
                'SELECT 1 FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id = w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_trx_id WHERE r.trx_mysql_thread_id = ? AND b.trx_mysql_thread_id = ?',
                [$requester, $blocker]
            );
            if ($waiting) {
                break;
            }
            usleep(20000);
        }
        $this->assertTrue($waiting, 'No se observó la espera InnoDB esperada.');
    }

    private function workerResult(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function runHttpFirst(string $operation): void
    {
        $org = $this->clientOrganization('CONC-FIRST');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $token = $user->createToken('concurrency')->plainTextToken;
        $db = DB::connection();
        config(['database.connections.concurrency_observer' => $db->getConfig()]);
        $prefix = tempnam(is_dir('/tmp/opencode') ? '/tmp/opencode' : sys_get_temp_dir(), 'area-conc-');
        $this->assertIsString($prefix);
        unlink($prefix);
        $files = [$prefix.'-a', $prefix.'-locked', $prefix.'-release', $prefix.'-b'];
        $common = [
            'environment' => $this->concurrencyEnvironment($db->getConfig()), 'plant_id' => $plant->id,
            'organization_id' => $org->id, 'token' => $token, 'code' => 'CONC-01',
        ];
        $first = null;
        $second = null;
        try {
            $first = $this->worker($common + ['signal' => $files[0], 'locked' => $files[1], 'release' => $files[2]]);
            $this->awaitSignal($files[1], $first);
            $firstId = json_decode(file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR)['connection_id'];
            $second = $this->worker($common + ['signal' => $files[3], 'operation' => $operation]);
            $this->awaitSignal($files[3], $second);
            $secondId = json_decode(file_get_contents($files[3]), true, 512, JSON_THROW_ON_ERROR)['connection_id'];
            $this->assertNotSame($firstId, $secondId);
            $this->awaitLock($secondId, $firstId, $second);
            file_put_contents($files[2], 'release');
            $one = $this->workerResult($first);
            $two = $this->workerResult($second);
            $this->assertSame(201, $one['status']);
            if ($operation === 'http') {
                $this->assertSame(422, $two['status']);
                $this->assertSame('validation_failed', $two['body']['code']);
                $this->assertArrayHasKey('code', $two['body']['errors']);
            } else {
                $this->assertSame('updated', $two['status']);
                $row = $db->table('plants')->where('id', $plant->id)->first();
                if ($operation === 'deactivate') {
                    $this->assertSame(0, (int) $row->status);
                } else {
                    $this->assertNotNull($row->deleted_at);
                }
            }
            $this->assertSame(1, $db->table('areas')->where('plant_id', $plant->id)->count());
            $this->assertSame(1, $db->table('audit_logs')->where('action', 'area.created')->count());
        } finally {
            foreach ([$first, $second] as $process) {
                if ($process && $process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            DB::purge('concurrency_observer');
        }
    }

    private function runDeleteFirst(): void
    {
        $org = $this->clientOrganization('CONC-DELETE');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        $token = $user->createToken('concurrency')->plainTextToken;
        $db = DB::connection();
        config(['database.connections.concurrency_observer' => $db->getConfig()]);
        $signal = tempnam(is_dir('/tmp/opencode') ? '/tmp/opencode' : sys_get_temp_dir(), 'area-conc-');
        $this->assertIsString($signal);
        unlink($signal);
        $process = null;
        try {
            $db->beginTransaction();
            $db->table('plants')->where('id', $plant->id)->update(['deleted_at' => now()]);
            $blocker = (int) $db->scalar('SELECT CONNECTION_ID()');
            $process = $this->worker([
                'environment' => $this->concurrencyEnvironment($db->getConfig()),
                'plant_id' => $plant->id, 'organization_id' => $org->id, 'token' => $token, 'signal' => $signal,
            ]);
            $this->awaitSignal($signal, $process);
            $requester = json_decode(file_get_contents($signal), true, 512, JSON_THROW_ON_ERROR)['connection_id'];
            $this->awaitLock($requester, $blocker, $process);
            $db->commit();
            $result = $this->workerResult($process);
            $this->assertSame(404, $result['status']);
            $this->assertSame('not_found', $result['body']['code']);
            $this->assertSame(0, $db->table('areas')->count());
            $this->assertSame(0, $db->table('audit_logs')->where('action', 'area.created')->count());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($process && $process->isRunning()) {
                $process->stop(1);
            }
            if (is_file($signal)) {
                unlink($signal);
            }
            DB::purge('concurrency_observer');
        }
    }
}
