<?php

namespace Tests\Feature\Database;

use App\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InspectionConcurrencyMariaDbTest extends TestCase
{
    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function fixture(): array
    {
        if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Solo MariaDB efímera.');
        }
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->assertStringEndsWith('_test', DB::getDatabaseName());
        $this->assertMatchesRegularExpression('/11\.8\.\d+.*MariaDB/i', DB::scalar('SELECT VERSION()'));
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $result = [];
        foreach (['1', '2'] as $n) {
            $ids = [];
            foreach (['organization', 'user', 'role', 'plant', 'area', 'line', 'checklist', 'version', 'section', 'question'] as $field) {
                $ids[$field] = (string) Str::uuid7();
            }
            DB::table('organizations')->insert(['id' => $ids['organization'], 'code' => "I$n", 'name' => "Inspecta $n", 'type' => 'client', 'status' => 1]);
            DB::table('users')->insert(['id' => $ids['user'], 'organization_id' => $ids['organization'], 'name' => "Inspector $n", 'email' => "inspecta-$n@example.test", 'password' => 'test', 'status' => 1]);
            DB::table('organization_memberships')->insert(['id' => (string) Str::uuid7(), 'user_id' => $ids['user'], 'organization_id' => $ids['organization'], 'status' => 'active']);
            DB::table('roles')->insert(['id' => $ids['role'], 'organization_id' => $ids['organization'], 'name' => "Inspecta $n", 'status' => 1]);
            DB::table('user_roles')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'user_id' => $ids['user'], 'role_id' => $ids['role']]);
            foreach ([Permission::InspectionsExecute, Permission::ChecklistsManage] as $permission) {
                DB::table('role_permissions')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'role_id' => $ids['role'], 'permission' => $permission->value]);
            }
            DB::table('plants')->insert(['id' => $ids['plant'], 'organization_id' => $ids['organization'], 'code' => "P$n", 'name' => "Plant $n", 'status' => 1]);
            DB::table('areas')->insert(['id' => $ids['area'], 'organization_id' => $ids['organization'], 'plant_id' => $ids['plant'], 'code' => "A$n", 'name' => "Area $n", 'status' => 1]);
            DB::table('production_lines')->insert(['id' => $ids['line'], 'organization_id' => $ids['organization'], 'area_id' => $ids['area'], 'code' => "L$n", 'name' => "Line $n", 'status' => 1]);
            DB::table('user_areas')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'user_id' => $ids['user'], 'area_id' => $ids['area']]);
            DB::table('checklists')->insert(['id' => $ids['checklist'], 'organization_id' => $ids['organization'], 'name' => "Checklist $n", 'status' => 1]);
            DB::table('checklist_versions')->insert(['id' => $ids['version'], 'organization_id' => $ids['organization'], 'checklist_id' => $ids['checklist'], 'created_by' => $ids['user'], 'version' => '1', 'status' => 'published', 'published_at' => now()]);
            DB::table('checklist_sections')->insert(['id' => $ids['section'], 'organization_id' => $ids['organization'], 'checklist_version_id' => $ids['version'], 'name' => 'Section', 'display_order' => 1]);
            DB::table('questions')->insert(['id' => $ids['question'], 'organization_id' => $ids['organization'], 'checklist_section_id' => $ids['section'], 'question_text' => 'Question', 'answer_type' => 'text', 'status' => 1, 'display_order' => 1]);
            $result[$n] = $ids;
        }

        return $result;
    }

    private function create(array $ids): array
    {
        return ['user_id' => $ids['user'], 'organization_id' => $ids['organization'], 'method' => 'POST', 'uri' => '/api/inspections', 'body' => ['checklist_version_id' => $ids['version'], 'plant_id' => $ids['plant'], 'area_id' => $ids['area'], 'production_line_id' => $ids['line']]];
    }

    private function pair(array $a, array $b, bool $ordered = false): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'ins01-barrier-');
        unlink($barrier);
        $secondBarrier = $ordered ? $barrier.'-second' : $barrier;
        try {
            $spawn = function (array $payload, string $startFile): array {
                $payload['start_file'] = $startFile;
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
                $worker = isset($payload['table']) ? 'InspectionLocationWorker.php' : 'EhsConcurrencyWorker.php';
                $process = proc_open([PHP_BINARY, base_path('tests/Support/'.$worker), 'run', base64_encode(json_encode($payload))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
                $this->assertIsResource($process);
                fclose($pipes[0]);

                return [$process, $pipes];
            };
            $first = $spawn($a, $barrier);
            $second = $spawn($b, $secondBarrier);
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
                if ($ordered && count($result) === 1) {
                    touch($secondBarrier);
                }
            }

            return $result;
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }
            if ($ordered && file_exists($secondBarrier)) {
                unlink($secondBarrier);
            }
        }
    }

    public function test_dos_creaciones_legitimas_y_organizaciones_independientes(): void
    {
        $ids = $this->fixture();
        foreach ([[$this->create($ids['1']), $this->create($ids['1'])], [$this->create($ids['1']), $this->create($ids['2'])]] as [$a, $b]) {
            [$first, $second] = $this->pair($a, $b);
            $this->assertSame(201, $first['status'], json_encode($first));
            $this->assertSame(201, $second['status'], json_encode($second));
            $this->assertNotSame($first['body']['data']['id'], $second['body']['data']['id']);
        }
        $this->assertSame(4, DB::table('inspections')->count());
        $this->assertSame(4, DB::table('audit_logs')->where('action', 'inspection.created')->count());
    }

    public function test_archivado_y_desactivacion_compiten_con_creacion(): void
    {
        foreach (['archive', 'checklist'] as $scenario) {
            $ids = $this->fixture()['1'];
            $other = $scenario === 'archive'
                ? ['user_id' => $ids['user'], 'organization_id' => $ids['organization'], 'method' => 'PATCH', 'uri' => '/api/checklists/'.$ids['checklist'].'/versions/'.$ids['version'], 'body' => ['status' => 'archived']]
                : ['user_id' => $ids['user'], 'organization_id' => $ids['organization'], 'method' => 'PATCH', 'uri' => '/api/checklists/'.$ids['checklist'], 'body' => ['status' => false]];
            [$create, $change] = $this->pair($this->create($ids), $other);
            $this->assertSame(200, $change['status'], json_encode($change));
            $this->assertContains($create['status'], [201, 422], json_encode($create));
            $count = DB::table('inspections')->count();
            $this->assertSame($create['status'] === 201 ? 1 : 0, $count);
            $this->assertSame($count, DB::table('audit_logs')->where('action', 'inspection.created')->count());
            $retry = $this->pair($this->create($ids), $this->create($ids));
            $this->assertSame(422, $retry[0]['status'], json_encode($retry[0]));
            $this->assertSame(422, $retry[1]['status'], json_encode($retry[1]));
        }
    }

    public function test_publicacion_de_otra_version_compite_con_creacion(): void
    {
        $ids = $this->fixture()['1'];
        $new = (string) Str::uuid7();
        $section = (string) Str::uuid7();
        DB::table('checklist_versions')->insert(['id' => $new, 'organization_id' => $ids['organization'], 'checklist_id' => $ids['checklist'], 'created_by' => $ids['user'], 'version' => '2', 'status' => 'draft']);
        DB::table('checklist_sections')->insert(['id' => $section, 'organization_id' => $ids['organization'], 'checklist_version_id' => $new, 'name' => 'New section', 'display_order' => 1]);
        DB::table('questions')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'checklist_section_id' => $section, 'question_text' => 'New question', 'answer_type' => 'text', 'status' => 1, 'display_order' => 1]);
        $publish = ['user_id' => $ids['user'], 'organization_id' => $ids['organization'], 'method' => 'PATCH', 'uri' => '/api/checklists/'.$ids['checklist'].'/versions/'.$new, 'body' => ['status' => 'published']];
        [$create, $change] = $this->pair($this->create($ids), $publish);
        $this->assertSame(200, $change['status'], json_encode($change));
        $this->assertContains($create['status'], [201, 422], json_encode($create));
        $count = DB::table('inspections')->count();
        $this->assertSame($create['status'] === 201 ? 1 : 0, $count);
        $this->assertSame($count, DB::table('audit_logs')->where('action', 'inspection.created')->count());
        $this->assertSame('archived', DB::table('checklist_versions')->where('id', $ids['version'])->value('status'));
        [$retry] = $this->pair($this->create($ids), $this->create($ids));
        $this->assertSame(422, $retry['status'], json_encode($retry));
    }

    public function test_desactivaciones_de_ubicacion_compiten_con_creacion(): void
    {
        foreach (['plants' => 'plant', 'areas' => 'area', 'production_lines' => 'line'] as $table => $field) {
            $ids = $this->fixture()['1'];
            $disable = ['table' => $table, 'id' => $ids[$field], 'organization_id' => $ids['organization']];
            [$create, $change] = $this->pair($this->create($ids), $disable);
            $this->assertSame(200, $change['status'], json_encode($change));
            $this->assertContains($create['status'], [201, 422], json_encode($create));
            $count = DB::table('inspections')->count();
            $this->assertSame($create['status'] === 201 ? 1 : 0, $count);
            $this->assertSame($count, DB::table('audit_logs')->where('action', 'inspection.created')->count());
            [$retry] = $this->pair($this->create($ids), $this->create($ids));
            $this->assertSame(422, $retry['status'], json_encode($retry));
        }
    }

    public function test_ambos_ordenes_deterministas_de_cambio_frente_a_creacion(): void
    {
        foreach (['archive', 'publish', 'checklist', 'plants', 'areas', 'production_lines'] as $scenario) {
            foreach ([true, false] as $createFirst) {
                $ids = $this->fixture()['1'];
                $create = $this->create($ids);
                if ($scenario === 'publish') {
                    $new = (string) Str::uuid7();
                    $section = (string) Str::uuid7();
                    DB::table('checklist_versions')->insert(['id' => $new, 'organization_id' => $ids['organization'], 'checklist_id' => $ids['checklist'], 'created_by' => $ids['user'], 'version' => '2', 'status' => 'draft']);
                    DB::table('checklist_sections')->insert(['id' => $section, 'organization_id' => $ids['organization'], 'checklist_version_id' => $new, 'name' => 'New section', 'display_order' => 1]);
                    DB::table('questions')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $ids['organization'], 'checklist_section_id' => $section, 'question_text' => 'New question', 'answer_type' => 'text', 'status' => 1, 'display_order' => 1]);
                }
                $change = match ($scenario) {
                    'archive', 'publish' => ['user_id' => $ids['user'], 'organization_id' => $ids['organization'], 'method' => 'PATCH', 'uri' => '/api/checklists/'.$ids['checklist'].'/versions/'.($scenario === 'publish' ? $new : $ids['version']), 'body' => ['status' => $scenario === 'publish' ? 'published' : 'archived']],
                    'checklist' => ['user_id' => $ids['user'], 'organization_id' => $ids['organization'], 'method' => 'PATCH', 'uri' => '/api/checklists/'.$ids['checklist'], 'body' => ['status' => false]],
                    default => ['table' => $scenario, 'id' => $ids[match ($scenario) {
                        'plants' => 'plant', 'areas' => 'area', default => 'line'
                    }], 'organization_id' => $ids['organization']],
                };
                [$first, $second] = $this->pair($createFirst ? $create : $change, $createFirst ? $change : $create, true);
                $creation = $createFirst ? $first : $second;
                $mutation = $createFirst ? $second : $first;
                $this->assertSame(200, $mutation['status'], json_encode($mutation));
                $this->assertSame($createFirst ? 201 : 422, $creation['status'], json_encode($creation));
                $this->assertSame($createFirst ? 1 : 0, DB::table('inspections')->count());
                $this->assertSame($createFirst ? 1 : 0, DB::table('audit_logs')->where('action', 'inspection.created')->count());
            }
        }
    }
}
