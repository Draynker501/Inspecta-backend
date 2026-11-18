<?php

namespace Tests\Feature\Inspections;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Inspection;
use App\Models\Question;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Inspections\InspectionReview;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class InspectionReviewConcurrencyTest extends TestCase
{
    use TenantFixtures;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_competing_reviews_are_serialized(): void
    {
        if (DB::connection()->getDriverName() !== 'mariadb' || getenv('INS04_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requiere MariaDB y INS04_CONCURRENCY=1; no se ejecutaron migraciones.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requiere pcntl_fork.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $org = $this->clientOrganization('S22');
        app(TenantContext::class)->set($org->id);
        $plant = $this->plant($org, 'P');
        $area = $this->area($plant, 'A');
        $owner = $this->user($org);
        $reviewers = [$this->user($org), $this->user($org)];
        $role = $this->role($org, 'reviewer');
        RolePermission::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'role_id' => $role->id, 'permission' => Permission::InspectionsManage->value]);
        foreach ($reviewers as $reviewer) {
            $reviewer->assignRole($role);
            DB::table('user_areas')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'user_id' => $reviewer->id, 'area_id' => $area->id]);
        }
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'Checklist', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'created_by' => $owner->id, 'version' => '1', 'status' => 'published', 'published_at' => now()]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'Sección', 'display_order' => 1]);
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Pregunta', 'answer_type' => 'boolean', 'display_order' => 1, 'required' => true, 'status' => true]);

        $makeInspection = function () use ($org, $owner, $version, $plant, $area, $question, $section): string {
            $inspection = Inspection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'user_id' => $owner->id, 'checklist_version_id' => $version->id, 'plant_id' => $plant->id, 'area_id' => $area->id, 'status' => 'completed', 'started_at' => now()->subDay(), 'completed_at' => now()]);
            DB::table('inspection_answers')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'inspection_id' => $inspection->id, 'question_id' => $question->id, 'checklist_version_id' => $version->id, 'checklist_section_id' => $section->id, 'answer' => 'false', 'created_at' => now(), 'updated_at' => now()]);

            return $inspection->id;
        };
        foreach ([['confirm', 'confirm'], ['reject', 'reject'], ['confirm', 'reject'], ['reject', 'confirm'], ['confirm', 'reject']] as $index => $actions) {
            $independent = $index === 4;
            $ids = [$makeInspection()];
            $ids[] = $independent ? $makeInspection() : $ids[0];
            $answers = DB::table('inspection_answers')->whereIn('inspection_id', $ids)->orderBy('id')->get()->toArray();
            [$results, $waits] = $this->race($org->id, $ids, [$reviewers[0]->id, $reviewers[1]->id], $actions, ! $independent);
            fwrite(STDERR, "\nS22 ".json_encode(['actions' => $actions, 'independent' => $independent, 'max_lock_wait' => $waits, 'results' => $results], JSON_UNESCAPED_SLASHES)."\n");
            if ($independent) {
                $this->assertSame(0, $waits, 'Las inspecciones independientes no deben bloquearse entre sí.');
            } else {
                $this->assertGreaterThanOrEqual(2, $waits, 'No se observó contención de ambos hijos en el FOR UPDATE.');
            }
            $successes = array_values(array_filter($results, fn ($r) => $r['ok']));
            $this->assertCount($independent ? 2 : 1, $successes, json_encode($results));
            if (! $independent) {
                $loser = array_values(array_filter($results, fn ($r) => ! $r['ok']))[0];
                $this->assertSame(ValidationException::class, $loser['exception']);
                $this->assertArrayHasKey('inspection', $loser['errors']);
            }
            foreach ($successes as $winner) {
                $inspection = DB::table('inspections')->where('id', $winner['inspection'])->sole();
                $status = $winner['action'] === 'confirm' ? 'confirmed' : 'rejected';
                $this->assertSame($status, $inspection->status);
                $this->assertSame($winner['actor'], $inspection->supervisor_id);
                $this->assertSame($winner['action'] === 'reject' ? 'motivo '.$winner['actor'] : null, $inspection->supervisor_observations);
                $this->assertSame($winner['action'] === 'confirm', $inspection->supervisor_confirmed_at !== null);
                $this->assertSame($winner['action'] === 'reject', $inspection->rejected_at !== null);
                $audits = DB::table('audit_logs')->where('auditable_id', $winner['inspection'])->whereIn('action', ['inspection.confirmed', 'inspection.rejected'])->get();
                $this->assertCount(1, $audits);
                $this->assertSame('inspection.'.$status, $audits[0]->action);
                $this->assertSame($winner['actor'], $audits[0]->user_id);
            }
            $this->assertEquals($answers, DB::table('inspection_answers')->whereIn('inspection_id', $ids)->orderBy('id')->get()->toArray());
        }
    }

    private function race(string $organization, array $ids, array $actors, array $actions, bool $block): array
    {
        $dir = sys_get_temp_dir().'/ins04-s22-race-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700)) {
            throw new \RuntimeException('No se pudo crear la barrera.');
        }
        $pids = [];
        $waiting = 0;
        // No compartir un socket PDO abierto entre padre e hijos.
        DB::purge();
        try {
            foreach ([0, 1] as $i) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Falló fork.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::reconnect();
                        app(TenantContext::class)->set($organization);
                        $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                        file_put_contents("$dir/ready-$i", (string) $connectionId);
                        $deadline = microtime(true) + 20;
                        while (! is_file("$dir/go")) {
                            if (microtime(true) > $deadline) {
                                throw new \RuntimeException('Timeout de barrera.');
                            }
                            usleep(10000);
                        }
                        $start = microtime(true);
                        $input = ['action' => $actions[$i]];
                        if ($actions[$i] === 'reject') {
                            $input['supervisor_observations'] = 'motivo '.$actors[$i];
                        }
                        $result = app(InspectionReview::class)->review(User::findOrFail($actors[$i]), $ids[$i], $input);
                        $out = ['ok' => true, 'status' => $result->status, 'supervisor_id' => $result->supervisor_id];
                    } catch (\Throwable $e) {
                        $out = ['ok' => false, 'exception' => $e::class, 'errors' => $e instanceof ValidationException ? $e->errors() : [], 'message' => $e->getMessage()];
                    }
                    $out += ['actor' => $actors[$i], 'action' => $actions[$i], 'inspection' => $ids[$i], 'start' => $start ?? null, 'end' => microtime(true)];
                    file_put_contents("$dir/result-$i", json_encode($out, JSON_THROW_ON_ERROR));
                    exit(0);
                }
                $pids[] = $pid;
            }
            $deadline = microtime(true) + 20;
            while (! is_file("$dir/ready-0") || ! is_file("$dir/ready-1")) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Hijos no preparados.');
                }
                usleep(10000);
            }
            $connections = [(int) file_get_contents("$dir/ready-0"), (int) file_get_contents("$dir/ready-1")];
            config(['database.connections.ins04_observer' => config('database.connections.'.config('database.default'))]);
            $observer = DB::connection('ins04_observer');
            if ($block) {
                // Bloqueo artificial del padre: ambos hijos deben esperar el
                // mismo FOR UPDATE antes de continuar.
                DB::reconnect();
                DB::beginTransaction();
                DB::table('inspections')->where('id', $ids[0])->lockForUpdate()->first();
            }
            touch("$dir/go");
            $deadline = microtime(true) + 15;
            do {
                $waiting = max($waiting, $this->forUpdateWaits($observer, $connections));
                $finished = is_file("$dir/result-0") && is_file("$dir/result-1");
                if (($block && $waiting >= 2) || (! $block && $finished)) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($block && $waiting < 2) {
                fwrite(STDERR, "\nS22 diagnóstico: no se observaron dos FOR UPDATE en espera ".json_encode([
                    'connections' => $connections,
                    'transactions' => $observer->select('SELECT trx_mysql_thread_id, trx_state, trx_query FROM information_schema.innodb_trx'),
                ])."\n");
            }
            if ($block) {
                DB::commit();
            }
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    throw new \RuntimeException('Hijo terminó anormalmente.');
                }
            }
            $pids = [];

            return [array_map(fn ($i) => json_decode(file_get_contents("$dir/result-$i"), true, 512, JSON_THROW_ON_ERROR), [0, 1]), $waiting];
        } finally {
            DB::purge('ins04_observer');
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($pids as $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob("$dir/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    /**
     * Cuenta las transacciones hijas detenidas en un FOR UPDATE.
     *
     * En esta MariaDB information_schema.innodb_trx puede omitir a las
     * sesiones bloqueadas, por lo que también se lee SHOW ENGINE INNODB
     * STATUS. Solo cuentan las esperas cuya consulta es el lockForUpdate del
     * servicio: así la prueba detecta la eliminación del bloqueo y no la
     * contención posterior de los UPDATE.
     */
    private function forUpdateWaits(ConnectionInterface $observer, array $connections): int
    {
        $rows = $observer->select('SHOW ENGINE INNODB STATUS');
        $status = (string) ($rows[0]->Status ?? '');

        $fromStatus = 0;
        foreach (preg_split('/^---TRANSACTION /m', $status) ?: [] as $block) {
            if (! preg_match('/(?:MariaDB|MySQL) thread id (\d+)/', $block, $match)) {
                continue;
            }
            if (! in_array((int) $match[1], $connections, true)) {
                continue;
            }
            if (! str_contains($block, 'LOCK WAIT') || ! str_contains($block, 'TRX HAS BEEN WAITING')) {
                continue;
            }
            if (! preg_match('/\bfor update\b/i', $block)) {
                continue;
            }
            $fromStatus++;
        }

        $fromTransactions = 0;
        foreach ($observer->select(
            "SELECT trx_mysql_thread_id FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT' AND trx_query LIKE '%for update%'"
        ) as $row) {
            if (in_array((int) $row->trx_mysql_thread_id, $connections, true)) {
                $fromTransactions++;
            }
        }

        return max($fromStatus, $fromTransactions);
    }
}
