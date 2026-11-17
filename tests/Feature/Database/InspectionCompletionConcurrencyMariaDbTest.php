<?php

namespace Tests\Feature\Database;

use App\Authorization\Permission;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InspectionCompletionConcurrencyMariaDbTest extends TestCase
{
    /** @var list<array{0: resource, 1: array<int, resource>}> */
    private array $workers = [];

    /** @var list<string> */
    private array $markers = [];

    protected function tearDown(): void
    {
        foreach ($this->markers as $marker) {
            if (str_ends_with($marker, '.release') && ! file_exists($marker)) {
                touch($marker);
            }
        }
        foreach ($this->workers as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        foreach ($this->markers as $marker) {
            if (file_exists($marker)) {
                unlink($marker);
            }
        }
        RefreshDatabaseState::$migrated = false;
        DB::purge('ins03_observer');
        parent::tearDown();
    }

    private function fixture(bool $answered = true): array
    {
        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('La contención de InnoDB requiere MariaDB aislada.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertSame('127.0.0.1', $connection->getConfig('host'));
        $this->assertStringEndsWith('_test', $connection->getDatabaseName());
        $this->assertMatchesRegularExpression('/^11\.8\.9-MariaDB/i', DB::scalar('SELECT VERSION()'));
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $ids = [];
        foreach (['organization', 'user', 'role', 'plant', 'area', 'checklist', 'version', 'section', 'question', 'inspection', 'second_inspection'] as $name) {
            $ids[$name] = (string) Str::uuid7();
        }
        DB::table('organizations')->insert(['id' => $ids['organization'], 'code' => 'INS03S22', 'name' => 'INS03 test', 'type' => 'client', 'status' => 1]);
        DB::table('users')->insert(['id' => $ids['user'], 'organization_id' => $ids['organization'], 'name' => 'Inspector', 'email' => 'ins03-s22@example.test', 'password' => 'test', 'status' => 1]);
        DB::table('organization_memberships')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'user_id' => $ids['user'], 'status' => 'active']);
        DB::table('roles')->insert(['id' => $ids['role'], 'organization_id' => $ids['organization'], 'name' => 'Inspector', 'status' => 1]);
        DB::table('user_roles')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'user_id' => $ids['user'], 'role_id' => $ids['role']]);
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'role_id' => $ids['role'], 'permission' => Permission::InspectionsExecute->value]);
        DB::table('plants')->insert(['id' => $ids['plant'], 'organization_id' => $ids['organization'], 'code' => 'INS03P', 'name' => 'Plant', 'status' => 1]);
        DB::table('areas')->insert(['id' => $ids['area'], 'organization_id' => $ids['organization'], 'plant_id' => $ids['plant'], 'code' => 'INS03A', 'name' => 'Area', 'status' => 1]);
        DB::table('user_areas')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'user_id' => $ids['user'], 'area_id' => $ids['area']]);
        DB::table('checklists')->insert(['id' => $ids['checklist'], 'organization_id' => $ids['organization'], 'name' => 'Checklist', 'status' => 1]);
        DB::table('checklist_versions')->insert(['id' => $ids['version'], 'organization_id' => $ids['organization'], 'checklist_id' => $ids['checklist'], 'created_by' => $ids['user'], 'version' => '1', 'status' => 'published', 'published_at' => now()]);
        DB::table('checklist_sections')->insert(['id' => $ids['section'], 'organization_id' => $ids['organization'], 'checklist_version_id' => $ids['version'], 'name' => 'Section', 'display_order' => 1]);
        DB::table('questions')->insert(['id' => $ids['question'], 'organization_id' => $ids['organization'], 'checklist_section_id' => $ids['section'], 'question_text' => 'Required', 'answer_type' => 'text', 'required' => 1, 'status' => 1, 'display_order' => 1]);
        foreach (['inspection', 'second_inspection'] as $name) {
            DB::table('inspections')->insert(['id' => $ids[$name], 'organization_id' => $ids['organization'], 'user_id' => $ids['user'], 'checklist_version_id' => $ids['version'], 'plant_id' => $ids['plant'], 'area_id' => $ids['area'], 'status' => 'draft', 'started_at' => now()]);
            if ($answered) {
                DB::table('inspection_answers')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'inspection_id' => $ids[$name], 'question_id' => $ids['question'], 'checklist_version_id' => $ids['version'], 'checklist_section_id' => $ids['section'], 'answer' => 'initial']);
            }
        }

        return $ids;
    }

    private function payload(array $ids, string $operation, string $inspection = 'inspection'): array
    {
        $base = '/api/inspections/'.$ids[$inspection];

        return [
            'user_id' => $ids['user'], 'organization_id' => $ids['organization'],
            'method' => match ($operation) {
                'put' => 'PUT', 'delete' => 'DELETE', default => 'POST'
            },
            'uri' => match ($operation) {
                'put', 'delete' => $base.'/answers/'.$ids['question'],
                default => $base.'/complete',
            },
            'body' => $operation === 'put' ? ['answer' => 'new'] : new \stdClass,
        ];
    }

    private function spawn(array $payload): int
    {
        $connection = config('database.connections.mariadb');
        $env = [
            'APP_BASE_PATH' => base_path(), 'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mariadb', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'], 'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'], 'DB_URL' => '',
            'TESTING_DB_ALLOW_EXTERNAL' => 'true',
        ];
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/InspectionCompletionConcurrencyWorker.php'), base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $this->workers[] = [$process, $pipes];

        return count($this->workers) - 1;
    }

    private function workerResult(int $index): array
    {
        [$process, $pipes] = $this->workers[$index];
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        unset($this->workers[$index]);
        $result = json_decode($stdout, true);
        $this->assertSame(0, $status, $stderr);
        $this->assertIsArray($result, $stderr.' '.$stdout);

        return $result;
    }

    private function observer(): Connection
    {
        config(['database.connections.ins03_observer' => config('database.connections.mariadb')]);
        DB::purge('ins03_observer');

        return DB::connection('ins03_observer');
    }

    private function waitFor(callable $predicate, string $description, float $seconds = 8): mixed
    {
        $deadline = microtime(true) + $seconds;
        do {
            $value = $predicate();
            if ($value) {
                return $value;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('No se observó: '.$description);
    }

    private function race(array $ids, string $first, string $second, bool $independent = false): array
    {
        $marker = tempnam(sys_get_temp_dir(), 'ins03-hold-');
        unlink($marker);
        $release = $marker.'.release';
        $this->markers[] = $marker;
        $this->markers[] = $release;
        $firstPayload = $this->payload($ids, $first);
        $firstPayload['hold_marker'] = $marker;
        $firstPayload['release_marker'] = $release;
        $a = $this->spawn($firstPayload);
        $this->waitFor(fn () => file_exists($marker), 'primer bloqueo de inspección');
        $b = $this->spawn($this->payload($ids, $second, $independent ? 'second_inspection' : 'inspection'));

        if ($independent) {
            $other = $this->waitFor(
                fn () => DB::table('inspections')->where('id', $ids['second_inspection'])->value('status') === 'completed',
                'finalización independiente antes de liberar el primer bloqueo',
            );
            $this->assertTrue($other);
            $this->assertSame('draft', DB::table('inspections')->where('id', $ids['inspection'])->value('status'));
        } else {
            $observer = $this->observer();
            $waiting = $this->waitFor(function () use ($observer, $b) {
                $status = (string) ($observer->selectOne('SHOW ENGINE INNODB STATUS')->Status ?? '');
                if (str_contains($status, 'TRX HAS BEEN WAITING')) {
                    $rows = $observer->select('SELECT INFO, TIME FROM information_schema.PROCESSLIST WHERE DB = ? AND TIME >= 1', [DB::getDatabaseName()]);
                    foreach ($rows as $row) {
                        if (preg_match('/\bselect\b.*\binspections\b.*\bfor update\b/is', (string) $row->INFO)) {
                            return (string) $row->INFO;
                        }
                    }
                }
                if (! proc_get_status($this->workers[$b][0])['running']) {
                    $this->fail('El segundo proceso terminó sin esperar: '.json_encode($this->workerResult($b)));
                }

                return null;
            }, 'espera InnoDB sobre inspección');
            $this->assertMatchesRegularExpression('/\bselect\b.*\binspections\b.*\bfor update\b/is', $waiting);
            $this->assertSame('draft', DB::table('inspections')->where('id', $ids['inspection'])->value('status'));
        }
        touch($release);

        return [$this->workerResult($a), $this->workerResult($b)];
    }

    public function test_put_first_then_complete_includes_answer(): void
    {
        $ids = $this->fixture(false);
        [$put, $complete] = $this->race($ids, 'put', 'complete');
        $this->assertSame(200, $put['status'], json_encode($put));
        $this->assertSame(200, $complete['status'], json_encode($complete));
        $this->assertSame('completed', DB::table('inspections')->where('id', $ids['inspection'])->value('status'));
        $this->assertSame('new', DB::table('inspection_answers')->where('inspection_id', $ids['inspection'])->value('answer'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_created')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.completed')->count());
    }

    public function test_complete_first_then_put_rejects_write(): void
    {
        $ids = $this->fixture();
        [$complete, $put] = $this->race($ids, 'complete', 'put');
        $this->assertSame(200, $complete['status'], json_encode($complete));
        $this->assertSame(422, $put['status'], json_encode($put));
        $this->assertArrayHasKey('inspection', $put['body']['errors']);
        $this->assertSame('initial', DB::table('inspection_answers')->where('inspection_id', $ids['inspection'])->value('answer'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.completed')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.answer_updated')->count());
    }

    public function test_delete_first_then_complete_rejects_incomplete(): void
    {
        $ids = $this->fixture();
        [$delete, $complete] = $this->race($ids, 'delete', 'complete');
        $this->assertSame(204, $delete['status'], json_encode($delete));
        $this->assertSame(422, $complete['status'], json_encode($complete));
        $this->assertArrayHasKey('answers', $complete['body']['errors']);
        $this->assertSame('draft', DB::table('inspections')->where('id', $ids['inspection'])->value('status'));
        $this->assertNull(DB::table('inspections')->where('id', $ids['inspection'])->value('completed_at'));
        $this->assertSame(0, DB::table('inspection_answers')->where('inspection_id', $ids['inspection'])->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.completed')->count());
    }

    public function test_complete_first_then_delete_rejects_write(): void
    {
        $ids = $this->fixture();
        [$complete, $delete] = $this->race($ids, 'complete', 'delete');
        $this->assertSame(200, $complete['status'], json_encode($complete));
        $this->assertSame(422, $delete['status'], json_encode($delete));
        $this->assertArrayHasKey('inspection', $delete['body']['errors']);
        $this->assertSame('initial', DB::table('inspection_answers')->where('inspection_id', $ids['inspection'])->value('answer'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.completed')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());
    }

    public function test_two_completions_only_one_audits(): void
    {
        $ids = $this->fixture();
        [$first, $second] = $this->race($ids, 'complete', 'complete');
        $this->assertSame(200, $first['status'], json_encode($first));
        $this->assertSame(422, $second['status'], json_encode($second));
        $this->assertArrayHasKey('inspection', $second['body']['errors']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.completed')->count());
    }

    public function test_independent_completions_progress_without_same_row_wait(): void
    {
        $ids = $this->fixture();
        [$first, $second] = $this->race($ids, 'complete', 'complete', true);
        $this->assertSame(200, $first['status'], json_encode($first));
        $this->assertSame(200, $second['status'], json_encode($second));
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'inspection.completed')->count());
        $this->assertSame(2, DB::table('inspections')->where('status', 'completed')->count());
    }
}
