<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

class EhsOrganizationLockMariaDbTest extends TestCase
{
    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_mutex_organizacional_serializa_misma_org_sin_bloquear_otra(): void
    {
        $db = DB::connection();
        if (! in_array($db->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Solo MariaDB efímera.');
        }
        $this->assertStringEndsWith('_test', $db->getDatabaseName());
        $this->assertSame('127.0.0.1', $db->getConfig('host'));
        Artisan::call('migrate:fresh', ['--force' => true]);
        $first = (string) Str::uuid7();
        $second = (string) Str::uuid7();
        $db->table('organizations')->insert([
            ['id' => $first, 'name' => 'A', 'code' => 'S2-LOCK-A', 'type' => 'client', 'status' => true],
            ['id' => $second, 'name' => 'B', 'code' => 'S2-LOCK-B', 'type' => 'client', 'status' => true],
        ]);
        $config = $db->getConfig();
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'], $config['port'], $db->getDatabaseName());
        $a = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $b = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $b->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $a->beginTransaction();
        try {
            $lock = $a->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE');
            $lock->execute([$first]);
            $other = $b->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE');
            $start = microtime(true);
            $other->execute([$second]);
            $this->assertLessThan(0.8, microtime(true) - $start, 'Una segunda organización no debe esperar el mutex ajeno.');
            $start = microtime(true);
            try {
                $other->execute([$first]);
                $this->fail('Dos conexiones independientes deben competir por el mismo mutex.');
            } catch (PDOException $exception) {
                $this->assertSame('1205', (string) $exception->errorInfo[1]);
                $this->assertGreaterThanOrEqual(0.8, microtime(true) - $start);
            }
        } finally {
            $a->rollBack();
        }
        $lock = $b->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE');
        $lock->execute([$first]);
        $this->assertSame($first, $lock->fetchColumn());
    }
}
