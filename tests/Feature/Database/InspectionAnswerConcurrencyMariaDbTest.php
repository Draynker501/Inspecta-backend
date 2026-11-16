<?php

namespace Tests\Feature\Database;

use App\Authorization\Permission;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Concurrencia de `inspection_answers` sobre MariaDB 11.8.
 *
 * Escenarios cubiertos:
 *
 * 1. Dos `PUT` simultáneos sobre la MISMA pregunta de la misma inspección:
 *    el bloqueo de la fila de `inspections` serializa las escrituras. Se
 *    comprueba la espera observada en el motor, que no hay duplicados y que
 *    la auditoría es coherente (una creación y como mucho una actualización).
 * 2. Dos `PUT` simultáneos sobre preguntas DISTINTAS de la misma
 *    inspección: también se serializan por el mismo bloqueo.
 * 3. `PUT` frente a `DELETE` en los DOS órdenes (primero el borrado y
 *    primero la escritura). El orden se impone con una segunda barrera, de
 *    modo que el resultado es determinista.
 * 4. Escrituras concurrentes sobre inspecciones DISTINTAS de la misma
 *    organización: cada respuesta conserva su inspección.
 * 5. Escrituras concurrentes sobre ORGANIZACIONES DISTINTAS: cada fila
 *    conserva su `organization_id` y no hay cruce de datos ni de auditoría.
 * 6. `DELETE` frente a la inserción CONCURRENTE de una evidencia por SQL
 *    aislado (proceso propio con PDO, fuera de la aplicación): el borrado o
 *    bien se rechaza con 422 y se conservan respuesta y evidencia, o bien la
 *    evidencia se rechaza por la FK y la respuesta se elimina. En ningún caso
 *    se admite un 500.
 * 7. `DELETE` repetido sobre una pregunta válida: es idempotente y no
 *    genera auditoría adicional.
 * 8. `PUT` idéntico: no crea duplicados, no cambia las marcas de tiempo y no
 *    genera auditoría adicional.
 *
 * Escenarios NO cubiertos (y por qué):
 *
 * - Interbloqueo entre el `DELETE` y la inserción de la evidencia: la
 *   ventana en la que ambos participantes discretizan la misma fila es
 *   inherentemente no determinista; se afirman invariantes y no valores
 *   exactos.
 * - Tiempo de espera de bloqueo agotado (`innodb_lock_wait_timeout`): no se
 *   fuerza porque produciría un 500 por tiempo de espera y no por un
 *   defecto de la aplicación.
 * - Aislamiento del motor con distintos niveles de aislamiento: solo se
 *   cubre el aislamiento lógico de la aplicación (tenant, política y
 *   auditoría).
 */
class InspectionAnswerConcurrencyMariaDbTest extends TestCase
{
    /**
     * Retención mínima del bloqueo de la inspección, en segundos. Garantiza
     * que la contentions es observable y que las peticiones quedan encoladas.
     */
    private const MINIMUM_HOLD = 0.5;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        DB::purge('ins02_observer');
        parent::tearDown();
    }

    private function assertMariaDb(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Solo MariaDB efímera.');
        }
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->assertStringEndsWith('_test', DB::getDatabaseName());
        $this->assertMatchesRegularExpression('/11\.8\.\d+.*MariaDB/i', DB::scalar('SELECT VERSION()'));
    }

    /**
     * Datos de una organización completa (usuario, permisos, ubicación,
     * checklist, versión, sección, dos preguntas y dos inspecciones).
     *
     * @return array<string, string>
     */
    private function organizationFixture(string $suffix): array
    {
        $ids = [];
        foreach ([
            'organization', 'user', 'role', 'plant', 'area', 'line', 'checklist',
            'version', 'section', 'question', 'other_question', 'inspection', 'other_inspection',
        ] as $field) {
            $ids[$field] = (string) Str::uuid7();
        }

        DB::table('organizations')->insert(['id' => $ids['organization'], 'code' => 'INS02'.$suffix, 'name' => 'Inspecta INS02 '.$suffix, 'type' => 'client', 'status' => 1]);
        DB::table('users')->insert(['id' => $ids['user'], 'organization_id' => $ids['organization'], 'name' => 'Inspector '.$suffix, 'email' => 'inspector-ins02-'.strtolower($suffix).'@example.test', 'password' => 'test', 'status' => 1]);
        DB::table('organization_memberships')->insert(['id' => (string) Str::uuid7(), 'user_id' => $ids['user'], 'organization_id' => $ids['organization'], 'status' => 'active']);
        DB::table('roles')->insert(['id' => $ids['role'], 'organization_id' => $ids['organization'], 'name' => 'Inspector INS02 '.$suffix, 'status' => 1]);
        DB::table('user_roles')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'user_id' => $ids['user'], 'role_id' => $ids['role']]);
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'role_id' => $ids['role'], 'permission' => Permission::InspectionsExecute->value]);
        DB::table('plants')->insert(['id' => $ids['plant'], 'organization_id' => $ids['organization'], 'code' => 'P'.$suffix, 'name' => 'Plant '.$suffix, 'status' => 1]);
        DB::table('areas')->insert(['id' => $ids['area'], 'organization_id' => $ids['organization'], 'plant_id' => $ids['plant'], 'code' => 'A'.$suffix, 'name' => 'Area '.$suffix, 'status' => 1]);
        DB::table('production_lines')->insert(['id' => $ids['line'], 'organization_id' => $ids['organization'], 'area_id' => $ids['area'], 'code' => 'L'.$suffix, 'name' => 'Line '.$suffix, 'status' => 1]);
        DB::table('user_areas')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'user_id' => $ids['user'], 'area_id' => $ids['area']]);
        DB::table('checklists')->insert(['id' => $ids['checklist'], 'organization_id' => $ids['organization'], 'name' => 'Checklist '.$suffix, 'status' => 1]);
        DB::table('checklist_versions')->insert(['id' => $ids['version'], 'organization_id' => $ids['organization'], 'checklist_id' => $ids['checklist'], 'created_by' => $ids['user'], 'version' => '1', 'status' => 'published', 'published_at' => now()]);
        DB::table('checklist_sections')->insert(['id' => $ids['section'], 'organization_id' => $ids['organization'], 'checklist_version_id' => $ids['version'], 'name' => 'Section '.$suffix, 'display_order' => 1]);
        DB::table('questions')->insert(['id' => $ids['question'], 'organization_id' => $ids['organization'], 'checklist_section_id' => $ids['section'], 'question_text' => 'Question '.$suffix, 'answer_type' => 'text', 'status' => 1, 'display_order' => 1]);
        DB::table('questions')->insert(['id' => $ids['other_question'], 'organization_id' => $ids['organization'], 'checklist_section_id' => $ids['section'], 'question_text' => 'Other question '.$suffix, 'answer_type' => 'text', 'status' => 1, 'display_order' => 2]);
        DB::table('inspections')->insert(['id' => $ids['inspection'], 'organization_id' => $ids['organization'], 'user_id' => $ids['user'], 'checklist_version_id' => $ids['version'], 'plant_id' => $ids['plant'], 'area_id' => $ids['area'], 'production_line_id' => $ids['line'], 'status' => 'draft', 'started_at' => now()]);
        DB::table('inspections')->insert(['id' => $ids['other_inspection'], 'organization_id' => $ids['organization'], 'user_id' => $ids['user'], 'checklist_version_id' => $ids['version'], 'plant_id' => $ids['plant'], 'area_id' => $ids['area'], 'production_line_id' => $ids['line'], 'status' => 'draft', 'started_at' => now()]);

        return $ids;
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function fixture(): array
    {
        $this->assertMariaDb();
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));

        return [$this->organizationFixture('A'), $this->organizationFixture('B')];
    }

    /**
     * Respuesta insertada directamente (sin auditoría) para preparar borrados.
     *
     * @param  array<string, string>  $ids
     */
    private function seedAnswer(array $ids, string $question = 'question'): string
    {
        $id = (string) Str::uuid7();
        DB::table('inspection_answers')->insert([
            'id' => $id,
            'organization_id' => $ids['organization'],
            'inspection_id' => $ids['inspection'],
            'question_id' => $ids[$question],
            'checklist_version_id' => $ids['version'],
            'checklist_section_id' => $ids['section'],
            'answer' => 'inicial',
        ]);

        return $id;
    }

    /**
     * Ejecuta dos peticiones concurrentes con barrera de salida.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  callable|null  $afterRelease  Se invoca con la barrera ya liberada
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: float}
     */
    private function pair(array $a, array $b, ?callable $afterRelease = null): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'ins02-barrier-');
        unlink($barrier);
        try {
            $first = $this->spawn($a, $barrier);
            $second = $this->spawn($b, $barrier);
            $started = microtime(true);
            touch($barrier);
            if ($afterRelease !== null) {
                $afterRelease();
            }
            $result = [];
            foreach ([$first, $second] as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                $decoded = json_decode(trim($stdout), true);
                $this->assertIsArray($decoded, $stderr.' '.$stdout);
                $result[] = $decoded;
            }

            return [$result[0], $result[1], microtime(true) - $started];
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }
    }

    /**
     * Espera a un proceso ya arrancado y devuelve su resultado decodificado.
     *
     * Se usa cuando cada proceso tiene un desenlace distinto y no se puede
     * clasificar por el contenido de la respuesta.
     *
     * @param  array{0: resource, 1: array<int, resource>}  $handle
     * @return array<string, mixed>
     */
    private function awaitResult(array $handle, string $label): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $decoded = json_decode(trim($stdout), true);
        $this->assertIsArray($decoded, $label.': '.$stderr.' '.$stdout);

        return $decoded;
    }

    /**
     * Igual que `pair()`, pero el segundo proceso solo arranca cuando el
     * primero ha terminado. Permite fijar el orden de las operaciones.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function orderedPair(array $a, array $b): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'ins02-barrier-');
        unlink($barrier);
        $secondBarrier = $barrier.'-second';
        try {
            $first = $this->spawn($a, $barrier);
            $second = $this->spawn($b, $secondBarrier);
            touch($barrier);
            $result = [];
            foreach ([$first, $second] as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                $decoded = json_decode(trim($stdout), true);
                $this->assertIsArray($decoded, $stderr.' '.$stdout);
                $result[] = $decoded;
                if (count($result) === 1) {
                    touch($secondBarrier);
                }
            }

            return [$result[0], $result[1]];
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }
            if (file_exists($secondBarrier)) {
                unlink($secondBarrier);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function spawn(array $payload, string $barrier): array
    {
        $payload['start_file'] = $barrier;
        $env = [
            'APP_BASE_PATH' => dirname(__DIR__, 3), 'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mariadb',
            'DB_HOST' => (string) config('database.connections.mariadb.host'),
            'DB_PORT' => (string) config('database.connections.mariadb.port'),
            'DB_DATABASE' => (string) config('database.connections.mariadb.database'),
            'DB_USERNAME' => (string) config('database.connections.mariadb.username'),
            'DB_PASSWORD' => (string) config('database.connections.mariadb.password'),
            'TESTING_DB_ALLOW_EXTERNAL' => 'true',
        ];
        $process = proc_open([PHP_BINARY, base_path('tests/Support/EhsConcurrencyWorker.php'), 'run', base64_encode(json_encode($payload))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($process);
        fclose($pipes[0]);

        return [$process, $pipes];
    }

    /**
     * Conexión independiente para observar el motor: las consultas de
     * `information_schema` hechas dentro de la transacción que retiene el
     * bloqueo no ven la espera de las demás sesiones.
     */
    private function observer(): Connection
    {
        config(['database.connections.ins02_observer' => array_merge(config('database.connections.mariadb'), ['name' => 'ins02_observer'])]);
        DB::purge('ins02_observer');

        return DB::connection('ins02_observer');
    }

    /**
     * Espera observable en el motor: una transacción bloqueada por el
     * bloqueo retenido desde el proceso de pruebas.
     */
    private function awaitLockWait(Connection $observer): ?string
    {
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            try {
                $status = $observer->selectOne('SHOW ENGINE INNODB STATUS');
            } catch (\Throwable $exception) {
                $this->fail('No se pudo leer el estado de InnoDB (se requiere el privilegio PROCESS): '.$exception->getMessage());
            }
            $text = (string) ($status->Status ?? '');
            if (str_contains($text, 'TRX HAS BEEN WAITING')) {
                return $text;
            }
            usleep(20000);
        }

        return null;
    }

    /**
     * Retiene el bloqueo, observa la espera y lo libera.
     */
    private function releaseAfterLockWait(Connection $observer, ?string &$lockWait): void
    {
        $lockWait = $this->awaitLockWait($observer);
        $deadline = microtime(true) + self::MINIMUM_HOLD;
        while (microtime(true) < $deadline) {
            usleep(20000);
        }
        DB::commit();
    }

    /**
     * @param  array<string, string>  $ids
     * @return array<string, mixed>
     */
    private function putPayload(array $ids, string $inspection, string $question, string $answer): array
    {
        return [
            'user_id' => $ids['user'],
            'organization_id' => $ids['organization'],
            'method' => 'PUT',
            'uri' => '/api/inspections/'.$inspection.'/answers/'.$question,
            'body' => ['answer' => $answer],
        ];
    }

    /**
     * @param  array<string, string>  $ids
     * @return array<string, mixed>
     */
    private function deletePayload(array $ids, string $inspection, string $question): array
    {
        return [
            'user_id' => $ids['user'],
            'organization_id' => $ids['organization'],
            'method' => 'DELETE',
            'uri' => '/api/inspections/'.$inspection.'/answers/'.$question,
            'body' => null,
        ];
    }

    public function test_dos_puts_concurrentes_sobre_la_misma_pregunta_no_duplican_ni_pierden_auditoria(): void
    {
        [$ids] = $this->fixture();
        $observer = $this->observer();
        $lockWait = null;

        // El proceso de pruebas retiene el bloqueo de la inspección: las dos
        // peticiones quedan encoladas y la contención es observable.
        DB::beginTransaction();
        DB::table('inspections')->where('id', $ids['inspection'])->lockForUpdate()->first();
        try {
            [$first, $second, $elapsed] = $this->pair(
                $this->putPayload($ids, $ids['inspection'], $ids['question'], 'respuesta A'),
                $this->putPayload($ids, $ids['inspection'], $ids['question'], 'respuesta B'),
                function () use ($observer, &$lockWait): void {
                    $this->releaseAfterLockWait($observer, $lockWait);
                },
            );
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::commit();
            }
        }

        $this->assertSame(200, $first['status'], json_encode($first));
        $this->assertSame(200, $second['status'], json_encode($second));
        $this->assertNotNull($lockWait, 'No se observó ninguna espera de bloqueo en el motor.');
        $this->assertStringContainsString('for update', $lockWait);
        $this->assertStringContainsString('inspections', $lockWait);
        $this->assertGreaterThanOrEqual(self::MINIMUM_HOLD, $elapsed, 'Las peticiones no llegaron a bloquearse por la inspección.');

        $this->assertSame(1, DB::table('inspection_answers')->count());
        $row = DB::table('inspection_answers')->first();
        $this->assertContains($row->answer, ['respuesta A', 'respuesta B']);
        $this->assertSame($ids['version'], $row->checklist_version_id);
        $this->assertSame($ids['section'], $row->checklist_section_id);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_created')->count());
        $this->assertLessThanOrEqual(1, DB::table('audit_logs')->where('action', 'inspection.answer_updated')->count());
        $this->assertSame(
            DB::table('audit_logs')->whereIn('action', ['inspection.answer_created', 'inspection.answer_updated'])->count(),
            DB::table('audit_logs')->where('auditable_type', 'App\\Models\\InspectionAnswer')->count(),
        );
        $this->assertGreaterThanOrEqual(1, DB::table('audit_logs')->where('auditable_id', $row->id)->count());
        $this->assertSame(
            0,
            DB::table('audit_logs')
                ->whereIn('action', ['inspection.answer_created', 'inspection.answer_updated'])
                ->where('auditable_id', '<>', $row->id)
                ->count(),
        );
    }

    public function test_puts_concurrentes_en_preguntas_distintas_crean_dos_respuestas(): void
    {
        [$ids] = $this->fixture();
        $observer = $this->observer();
        $lockWait = null;

        DB::beginTransaction();
        DB::table('inspections')->where('id', $ids['inspection'])->lockForUpdate()->first();
        try {
            [$first, $second, $elapsed] = $this->pair(
                $this->putPayload($ids, $ids['inspection'], $ids['question'], 'primera'),
                $this->putPayload($ids, $ids['inspection'], $ids['other_question'], 'segunda'),
                function () use ($observer, &$lockWait): void {
                    $this->releaseAfterLockWait($observer, $lockWait);
                },
            );
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::commit();
            }
        }

        $this->assertSame(200, $first['status'], json_encode($first));
        $this->assertSame(200, $second['status'], json_encode($second));
        $this->assertNotNull($lockWait, 'No se observó ninguna espera de bloqueo en el motor.');
        $this->assertGreaterThanOrEqual(self::MINIMUM_HOLD, $elapsed);

        $this->assertSame(2, DB::table('inspection_answers')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'inspection.answer_created')->count());
        $answers = DB::table('inspection_answers')->get();
        $this->assertSame($ids['inspection'], $answers->firstWhere('question_id', $ids['question'])->inspection_id);
        $this->assertSame($ids['inspection'], $answers->firstWhere('question_id', $ids['other_question'])->inspection_id);
        $this->assertSame($ids['organization'], $answers->firstWhere('question_id', $ids['question'])->organization_id);
        $this->assertSame($ids['organization'], $answers->firstWhere('question_id', $ids['other_question'])->organization_id);
        $this->assertSame($ids['version'], $answers->firstWhere('question_id', $ids['question'])->checklist_version_id);
        $this->assertSame($ids['section'], $answers->firstWhere('question_id', $ids['question'])->checklist_section_id);
    }

    public function test_put_y_delete_concurrentes_en_ambos_ordenes_no_dejan_duplicados(): void
    {
        // Orden 1: el borrado se completa antes de la escritura.
        [$ids] = $this->fixture();
        $this->seedAnswer($ids);
        [$delete, $put] = $this->orderedPair(
            $this->deletePayload($ids, $ids['inspection'], $ids['question']),
            $this->putPayload($ids, $ids['inspection'], $ids['question'], 'actualizada'),
        );

        $this->assertSame(204, $delete['status'], json_encode($delete));
        $this->assertSame(200, $put['status'], json_encode($put));
        $this->assertSame(1, DB::table('inspection_answers')->count());
        $row = DB::table('inspection_answers')->first();
        $this->assertSame('actualizada', $row->answer);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_created')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.answer_updated')->count());

        // Orden 2: la escritura se completa antes del borrado.
        [$ids] = $this->fixture();
        $this->seedAnswer($ids);
        [$put, $delete] = $this->orderedPair(
            $this->putPayload($ids, $ids['inspection'], $ids['question'], 'actualizada'),
            $this->deletePayload($ids, $ids['inspection'], $ids['question']),
        );

        $this->assertSame(200, $put['status'], json_encode($put));
        $this->assertSame(204, $delete['status'], json_encode($delete));
        $this->assertSame(0, DB::table('inspection_answers')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_updated')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.answer_created')->count());
    }

    /**
     * `PUT` y `DELETE` realmente solapados sobre la misma pregunta: ambos
     * arrancan con la misma barrera y compiten por el bloqueo de la
     * inspección. La contención se observa en el motor; el orden final lo
     * decide el planificador, pero las dos trayectorias posibles deben ser
     * coherentes: como mucho una respuesta y auditoría correspondiente a las
     * operaciones aplicadas, sin duplicados ni rastros huérfanos.
     */
    public function test_put_y_delete_solapados_comparten_bloqueo_y_no_dejan_estado_incoherente(): void
    {
        [$ids] = $this->fixture();
        $this->seedAnswer($ids);
        $observer = $this->observer();
        $lockWait = null;

        DB::beginTransaction();
        DB::table('inspections')->where('id', $ids['inspection'])->lockForUpdate()->first();
        try {
            [$put, $delete, $elapsed] = $this->pair(
                $this->putPayload($ids, $ids['inspection'], $ids['question'], 'actualizada'),
                $this->deletePayload($ids, $ids['inspection'], $ids['question']),
                function () use ($observer, &$lockWait): void {
                    $this->releaseAfterLockWait($observer, $lockWait);
                },
            );
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::commit();
            }
        }

        $this->assertSame(200, $put['status'], json_encode($put));
        $this->assertSame(204, $delete['status'], json_encode($delete));
        $this->assertNotNull($lockWait, 'No se observó contención real en el motor.');
        $this->assertGreaterThanOrEqual(self::MINIMUM_HOLD, $elapsed, 'Las peticiones no llegaron a bloquearse por la inspección.');

        $rows = DB::table('inspection_answers')->where('question_id', $ids['question'])->get();
        $this->assertLessThanOrEqual(1, $rows->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());

        if ($rows->isEmpty()) {
            // El borrado se aplicó el último: no queda respuesta ni creación.
            $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.answer_created')->count());
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_updated')->count());

            return;
        }

        // La escritura se aplicó el último: la respuesta existe con su valor.
        $this->assertSame('actualizada', $rows->first()->answer);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_created')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.answer_updated')->count());
    }

    public function test_escrituras_concurrentes_sobre_inspecciones_distintas_no_se_duplican(): void
    {
        [$ids] = $this->fixture();

        [$first, $second] = $this->pair(
            $this->putPayload($ids, $ids['inspection'], $ids['question'], 'inspeccion uno'),
            $this->putPayload($ids, $ids['other_inspection'], $ids['other_question'], 'inspeccion dos'),
        );

        $this->assertSame(200, $first['status'], json_encode($first));
        $this->assertSame(200, $second['status'], json_encode($second));
        $this->assertSame(2, DB::table('inspection_answers')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'inspection.answer_created')->count());

        $answers = DB::table('inspection_answers')->get();
        $this->assertSame($ids['inspection'], $answers->firstWhere('question_id', $ids['question'])->inspection_id);
        $this->assertSame($ids['other_inspection'], $answers->firstWhere('question_id', $ids['other_question'])->inspection_id);
        $this->assertSame($ids['organization'], $answers->firstWhere('question_id', $ids['question'])->organization_id);
        $this->assertSame($ids['organization'], $answers->firstWhere('question_id', $ids['other_question'])->organization_id);
        $this->assertSame(
            2,
            DB::table('audit_logs')->where('action', 'inspection.answer_created')->where('organization_id', $ids['organization'])->count(),
        );
    }

    public function test_escrituras_concurrentes_sobre_organizaciones_distintas_no_se_cruzan(): void
    {
        [$a, $b] = $this->fixture();

        [$first, $second] = $this->pair(
            $this->putPayload($a, $a['inspection'], $a['question'], 'respuesta de A'),
            $this->putPayload($b, $b['inspection'], $b['question'], 'respuesta de B'),
        );

        $this->assertSame(200, $first['status'], json_encode($first));
        $this->assertSame(200, $second['status'], json_encode($second));
        $this->assertSame(2, DB::table('inspection_answers')->count());

        $answers = DB::table('inspection_answers')->get();
        $this->assertSame($a['organization'], $answers->firstWhere('question_id', $a['question'])->organization_id);
        $this->assertSame($a['inspection'], $answers->firstWhere('question_id', $a['question'])->inspection_id);
        $this->assertSame($b['organization'], $answers->firstWhere('question_id', $b['question'])->organization_id);
        $this->assertSame($b['inspection'], $answers->firstWhere('question_id', $b['question'])->inspection_id);

        $audits = DB::table('audit_logs')->where('action', 'inspection.answer_created')->get();
        $this->assertCount(2, $audits);
        $this->assertSame($a['organization'], $audits->firstWhere('auditable_id', $answers->firstWhere('question_id', $a['question'])->id)->organization_id);
        $this->assertSame($b['organization'], $audits->firstWhere('auditable_id', $answers->firstWhere('question_id', $b['question'])->id)->organization_id);
    }

    public function test_delete_concurrente_con_evidencia_ya_insertada_responde_422_y_conserva_los_datos(): void
    {
        [$ids] = $this->fixture();
        $answerId = $this->seedAnswer($ids);
        $evidenceId = (string) Str::uuid7();

        // La evidencia se inserta con SQL aislado antes de que el borrado
        // alcance el motor: el borrado debe rechazarse con 422.
        $insert = $this->insertEvidence($evidenceId, $ids, $answerId);
        $this->assertSame('inserted', $insert['status'], json_encode($insert));

        [$delete, $other] = $this->pair(
            $this->deletePayload($ids, $ids['inspection'], $ids['question']),
            $this->putPayload($ids, $ids['inspection'], $ids['other_question'], 'otra'),
        );

        $this->assertSame(422, $delete['status'], json_encode($delete));
        $this->assertArrayHasKey('answer', $delete['body']['errors'] ?? []);
        $this->assertSame(200, $other['status'], json_encode($other));
        $this->assertSame(1, DB::table('inspection_answers')->where('id', $answerId)->count());
        $this->assertSame(1, DB::table('evidences')->where('id', $evidenceId)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());
    }

    public function test_delete_concurrente_frente_a_insercion_concurrente_de_evidencia_no_produce_500(): void
    {
        [$ids] = $this->fixture();
        $answerId = $this->seedAnswer($ids);
        $evidenceId = (string) Str::uuid7();

        $barrier = tempnam(sys_get_temp_dir(), 'ins02-barrier-');
        unlink($barrier);
        $script = $this->evidenceScript();
        try {
            $delete = $this->spawn($this->deletePayload($ids, $ids['inspection'], $ids['question']), $barrier);
            $evidence = $this->spawnEvidence($script, $barrier, $evidenceId, $ids, $answerId);
            touch($barrier);

            // Cada resultado se lee de SU proceso: clasificar por contenido
            // haría inalcanzable el desenlace en que la inserción falla.
            $deleteResult = $this->awaitResult($delete, 'borrado');
            $evidenceResult = $this->awaitResult($evidence, 'evidencia');

            $this->assertContains($deleteResult['status'], [204, 422], json_encode($deleteResult));

            $storedAnswer = DB::table('inspection_answers')->where('id', $answerId)->count();
            $storedEvidence = DB::table('evidences')->where('id', $evidenceId)->count();

            if ($deleteResult['status'] === 422) {
                // La evidencia entró primero: la FK RESTRICT rechaza el
                // borrado y ambos registros se conservan.
                $this->assertSame('inserted', $evidenceResult['status'], json_encode($evidenceResult));
                $this->assertArrayHasKey('answer', $deleteResult['body']['errors'] ?? []);
                $this->assertSame(1, $storedAnswer);
                $this->assertSame(1, $storedEvidence);
                $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());

                return;
            }

            // El borrado confirmó antes de que la inserción comprobara la FK:
            // la evidencia se rechaza por integridad y no se convierte en 500.
            $this->assertSame('failed', $evidenceResult['status'], json_encode($evidenceResult));
            $this->assertSame('23000', (string) ($evidenceResult['code'] ?? ''), json_encode($evidenceResult));
            $this->assertStringContainsStringIgnoringCase('foreign key', (string) ($evidenceResult['error'] ?? ''));
            $this->assertSame(0, $storedAnswer);
            $this->assertSame(0, $storedEvidence);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }
            if (file_exists($script)) {
                unlink($script);
            }
        }
    }

    /**
     * Desenlace «el borrado gana» de la carrera anterior, fijado de forma
     * determinista: la respuesta ya no existe cuando se intenta insertar la
     * evidencia, de modo que la FK la rechaza con 23000 y sus invariantes se
     * ejercitan siempre, no solo cuando el planificador lo permite.
     */
    public function test_una_insercion_de_evidencia_tras_el_borrado_se_rechaza_por_fk(): void
    {
        [$ids] = $this->fixture();
        $answerId = $this->seedAnswer($ids);
        $evidenceId = (string) Str::uuid7();

        $delete = $this->single($this->deletePayload($ids, $ids['inspection'], $ids['question']));
        $this->assertSame(204, $delete['status'], json_encode($delete));
        $this->assertSame(0, DB::table('inspection_answers')->where('id', $answerId)->count());

        $insert = $this->insertEvidence($evidenceId, $ids, $answerId);
        $this->assertSame('failed', $insert['status'], json_encode($insert));
        $this->assertSame('23000', (string) ($insert['code'] ?? ''), json_encode($insert));
        $this->assertStringContainsStringIgnoringCase('foreign key', (string) ($insert['error'] ?? ''));
        $this->assertSame(0, DB::table('evidences')->where('id', $evidenceId)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());
    }

    /**
     * Ejecuta una petición aislada y devuelve su respuesta.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function single(array $payload): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'ins02-barrier-');
        unlink($barrier);
        try {
            [$process, $pipes] = $this->spawn($payload, $barrier);
            touch($barrier);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $decoded = json_decode(trim($stdout), true);
            $this->assertIsArray($decoded, $stderr.' '.$stdout);

            return $decoded;
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }
    }

    public function test_delete_repetido_sobre_pregunta_valida_no_genera_auditoria_adicional(): void
    {
        [$ids] = $this->fixture();
        $answerId = $this->seedAnswer($ids);

        [$first] = $this->pair(
            $this->deletePayload($ids, $ids['inspection'], $ids['question']),
            $this->putPayload($ids, $ids['inspection'], $ids['other_question'], 'otra'),
        );
        [$second] = $this->pair(
            $this->deletePayload($ids, $ids['inspection'], $ids['question']),
            $this->putPayload($ids, $ids['inspection'], $ids['other_question'], 'otra'),
        );

        $this->assertSame(204, $first['status'], json_encode($first));
        $this->assertSame(204, $second['status'], json_encode($second));
        $this->assertSame(0, DB::table('inspection_answers')->where('id', $answerId)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());
        $this->assertSame(
            0,
            DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->where('auditable_id', '<>', $answerId)->count(),
        );
    }

    /**
     * Una violación de integridad que NO procede de `evidences` no se traduce
     * a 422: se propaga y la respuesta se conserva.
     *
     * Se crea una segunda tabla que referencia `inspection_answers` (con el
     * mismo conjunto de caracteres y collation que la tabla padre, requisito
     * de MariaDB para la FK) y se inserta una fila que bloquea el borrado. El
     * nombre contiene «evidences» a propósito: una comparación por subcadena
     * del mensaje del motor produciría un falso positivo.
     */
    public function test_un_error_de_integridad_distinto_de_evidencias_no_se_traduce_a_422(): void
    {
        [$ids] = $this->fixture();
        $answerId = $this->seedAnswer($ids);

        $meta = DB::selectOne(
            'SELECT TABLE_COLLATION AS collation
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['inspection_answers'],
        );
        $charset = Str::before((string) $meta->collation, '_');
        $type = DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['inspection_answers', 'id'],
        )->column_type;

        DB::statement("CREATE TABLE archived_evidences (
            id {$type} NOT NULL PRIMARY KEY,
            inspection_answer_id {$type} NOT NULL,
            CONSTRAINT archived_evidences_answer_fk FOREIGN KEY (inspection_answer_id)
                REFERENCES inspection_answers (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$meta->collation}");
        DB::table('archived_evidences')->insert([
            'id' => (string) Str::uuid7(),
            'inspection_answer_id' => $answerId,
        ]);

        $delete = $this->single($this->deletePayload($ids, $ids['inspection'], $ids['question']));

        $this->assertSame(500, $delete['status'], json_encode($delete));
        $this->assertSame(1, DB::table('inspection_answers')->where('id', $answerId)->count());
        $this->assertSame(1, DB::table('archived_evidences')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.answer_deleted')->count());
    }

    public function test_put_identico_no_crea_duplicados_ni_cambia_timestamps_ni_audita(): void
    {
        [$ids] = $this->fixture();

        [$first] = $this->pair(
            $this->putPayload($ids, $ids['inspection'], $ids['question'], 'misma respuesta'),
            $this->putPayload($ids, $ids['inspection'], $ids['other_question'], 'otra'),
        );
        $before = DB::table('inspection_answers')->where('question_id', $ids['question'])->first();
        $this->assertSame(200, $first['status'], json_encode($first));

        // La pausa garantiza que una segunda escritura cambiaría la marca de
        // tiempo: la comparación de `updated_at` sería significativa.
        sleep(1);

        [$second] = $this->pair(
            $this->putPayload($ids, $ids['inspection'], $ids['question'], 'misma respuesta'),
            $this->putPayload($ids, $ids['inspection'], $ids['other_question'], 'otra'),
        );
        $after = DB::table('inspection_answers')->where('question_id', $ids['question'])->first();

        $this->assertSame(200, $second['status'], json_encode($second));
        $this->assertSame(2, DB::table('inspection_answers')->count());
        $this->assertSame($before->id, $after->id);
        $this->assertSame((string) $before->created_at, (string) $after->created_at);
        $this->assertSame((string) $before->updated_at, (string) $after->updated_at);
        $this->assertSame('misma respuesta', $after->answer);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'inspection.answer_created')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inspection.answer_updated')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('auditable_id', $after->id)->count());
    }

    /**
     * Inserción de una evidencia con SQL aislado (no pasa por la aplicación).
     *
     * @param  array<string, string>  $ids
     * @return array<string, mixed>
     */
    private function insertEvidence(string $evidenceId, array $ids, string $answerId): array
    {
        $script = $this->evidenceScript();
        $barrier = tempnam(sys_get_temp_dir(), 'ins02-barrier-');
        try {
            [$process, $pipes] = $this->spawnEvidence($script, $barrier, $evidenceId, $ids, $answerId);
            $output = stream_get_contents($pipes[1]);
            $errorOutput = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $decoded = json_decode(trim($output), true);
            $this->assertIsArray($decoded, $errorOutput.' '.$output);

            return $decoded;
        } finally {
            if (file_exists($script)) {
                unlink($script);
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }
    }

    /**
     * Script PHP aislado (PDO) que inserta una evidencia.
     */
    private function evidenceScript(): string
    {
        // `tempnam()` crea el archivo: se retira antes de escribir el script
        // para no dejar residuos en el directorio temporal.
        $script = tempnam(sys_get_temp_dir(), 'ins02-evidence-');
        unlink($script);
        $script .= '.php';
        $code = <<<'PHP'
<?php

$payload = json_decode(base64_decode((string) ($argv[1] ?? '')), true);
if (! is_array($payload)) {
    fwrite(STDERR, "payload invalido\n");
    exit(2);
}

$deadline = microtime(true) + 30;
while (! file_exists((string) $payload['start_file']) && microtime(true) < $deadline) {
    usleep(10000);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $payload['host'],
    $payload['port'],
    $payload['database'],
);

try {
    $pdo = new PDO($dsn, $payload['username'], $payload['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'INSERT INTO `evidences` (`id`, `organization_id`, `inspection_id`, `inspection_answer_id`, `uploaded_by`, `file_name`, `storage_path`, `created_at`, `updated_at`)'
        .' VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $statement->execute([
        $payload['evidence_id'],
        $payload['organization_id'],
        $payload['inspection_id'],
        $payload['inspection_answer_id'],
        $payload['uploaded_by'],
        'evidencia.jpg',
        'evidencias/evidencia.jpg',
    ]);
    $pdo->commit();
    echo json_encode(['status' => 'inserted']), PHP_EOL;
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'failed', 'error' => $exception->getMessage(), 'code' => $exception->getCode()]), PHP_EOL;
}
PHP;
        file_put_contents($script, $code);

        return $script;
    }

    /**
     * Proceso aislado que inserta la evidencia mediante SQL directo.
     *
     * @param  array<string, string>  $ids
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function spawnEvidence(string $script, string $barrier, string $evidenceId, array $ids, string $answerId): array
    {
        $payload = [
            'start_file' => $barrier,
            'evidence_id' => $evidenceId,
            'organization_id' => $ids['organization'],
            'inspection_id' => $ids['inspection'],
            'inspection_answer_id' => $answerId,
            'uploaded_by' => $ids['user'],
            'host' => (string) config('database.connections.mariadb.host'),
            'port' => (string) config('database.connections.mariadb.port'),
            'database' => (string) config('database.connections.mariadb.database'),
            'username' => (string) config('database.connections.mariadb.username'),
            'password' => (string) config('database.connections.mariadb.password'),
        ];
        $process = proc_open([PHP_BINARY, $script, base64_encode(json_encode($payload))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        fclose($pipes[0]);

        return [$process, $pipes];
    }
}
