<?php

namespace Tests\Feature\Database;

use App\Authorization\Permission;
use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Question;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EhsConcurrencyMariaDbTest extends TestCase
{
    private string $startFile;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        if (isset($this->startFile) && file_exists($this->startFile)) {
            unlink($this->startFile);
        }
        parent::tearDown();
    }

    private function assertMariaDb(): void
    {
        $db = DB::connection();
        if (! in_array($db->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Solo MariaDB efímera.');
        }
        $this->assertStringEndsWith('_test', $db->getDatabaseName());
        $this->assertSame('127.0.0.1', $db->getConfig('host'));
    }

    private function seedBase(): array
    {
        $this->assertMariaDb();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $org = Organization::create(['id' => (string) Str::uuid7(), 'name' => 'Concurrencia', 'code' => 'CONC', 'type' => Organization::TYPE_CLIENT, 'status' => true]);
        $other = Organization::create(['id' => (string) Str::uuid7(), 'name' => 'Otra', 'code' => 'OTHER', 'type' => Organization::TYPE_CLIENT, 'status' => true]);
        $user = User::factory()->create(['status' => true]);
        $user->organization_id = $org->id;
        $user->save();
        OrganizationMembership::create(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'organization_id' => $org->id, 'status' => OrganizationMembership::STATUS_ACTIVE]);
        $role = Role::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'conc-'.Str::random(8), 'status' => true]);
        app(TenantContext::class)->set($org->id);
        $user->assignRole($role);
        foreach ([Permission::ChecklistsView->value, Permission::ChecklistsManage->value, Permission::NormsView->value, Permission::NormsManage->value] as $permission) {
            RolePermission::create(['id' => (string) Str::uuid7(), 'role_id' => $role->id, 'organization_id' => $org->id, 'permission' => $permission]);
        }
        $checklist = Checklist::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'name' => 'Checklist concurrente', 'status' => true]);
        $version = ChecklistVersion::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_id' => $checklist->id, 'version' => '1.0', 'status' => 'draft', 'created_by' => $user->id]);
        $section = ChecklistSection::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_version_id' => $version->id, 'name' => 'Sección', 'display_order' => 1]);
        $question = Question::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'checklist_section_id' => $section->id, 'question_text' => 'Pregunta', 'answer_type' => 'text', 'display_order' => 1, 'status' => true]);
        $norm = Norm::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'code' => 'N', 'name' => 'Norma', 'status' => true]);
        $requirement = NormRequirement::create(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'norm_id' => $norm->id, 'code' => 'R', 'description' => 'Requisito']);

        return compact('org', 'other', 'user', 'checklist', 'version', 'section', 'question', 'norm', 'requirement');
    }

    private function runPair(array $a, array $b): array
    {
        $this->startFile = sys_get_temp_dir().'/ehs-conc-'.Str::uuid7();
        if (file_exists($this->startFile)) {
            unlink($this->startFile);
        }
        $a['start_file'] = $this->startFile;
        $b['start_file'] = $this->startFile;
        $procA = $this->spawn($a);
        $procB = $this->spawn($b);
        touch($this->startFile);
        $outA = $this->wait($procA);
        $outB = $this->wait($procB);
        unlink($this->startFile);
        unset($this->startFile);

        return [$outA, $outB];
    }

    private function spawn(array $payload): array
    {
        $env = [
            'APP_BASE_PATH' => dirname(__DIR__, 3),
            'APP_DEBUG' => 'true',
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mariadb',
            'DB_HOST' => (string) config('database.connections.mariadb.host'),
            'DB_PORT' => (string) config('database.connections.mariadb.port'),
            'DB_DATABASE' => (string) config('database.connections.mariadb.database'),
            'DB_USERNAME' => (string) config('database.connections.mariadb.username'),
            'DB_PASSWORD' => (string) config('database.connections.mariadb.password'),
        ];
        $cmd = [PHP_BINARY, dirname(__DIR__, 3).'/tests/Support/EhsConcurrencyWorker.php', 'run', base64_encode(json_encode($payload))];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 3), $env);
        $this->assertIsResource($process);
        fclose($pipes[0]);

        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    private function wait(array $handle): array
    {
        $stdout = stream_get_contents($handle['stdout']);
        $stderr = stream_get_contents($handle['stderr']);
        fclose($handle['stdout']);
        fclose($handle['stderr']);
        proc_close($handle['process']);
        $decoded = json_decode(trim($stdout), true);

        return is_array($decoded) ? $decoded : ['status' => 0, 'body' => null, 'raw' => $stdout, 'stderr' => $stderr];
    }

    public function test_publicaciones_simultaneas_del_mismo_checklist(): void
    {
        $data = $this->seedBase();
        $payload = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PATCH', 'uri' => '/api/checklists/'.$data['checklist']->id.'/versions/'.$data['version']->id, 'body' => ['status' => 'published']];
        [$a, $b] = $this->runPair($payload, $payload);
        $this->assertNotSame(500, $a['status']);
        $this->assertNotSame(500, $b['status']);
        $version = DB::table('checklist_versions')->where('id', $data['version']->id)->first();
        $this->assertSame('published', $version->status);
        $this->assertNotNull($version->published_at);
    }

    public function test_publicacion_frente_a_edicion_de_pregunta(): void
    {
        $data = $this->seedBase();
        $publish = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PATCH', 'uri' => '/api/checklists/'.$data['checklist']->id.'/versions/'.$data['version']->id, 'body' => ['status' => 'published']];
        $edit = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PUT', 'uri' => '/api/questions/'.$data['question']->id, 'body' => ['question_text' => 'Editada concurrentemente', 'answer_type' => 'text']];
        [$a, $b] = $this->runPair($publish, $edit);
        $this->assertNotSame(500, $a['status']);
        $this->assertNotSame(500, $b['status']);
        $version = DB::table('checklist_versions')->where('id', $data['version']->id)->first();
        $this->assertContains($version->status, ['draft', 'published']);
    }

    public function test_publicacion_frente_a_edicion_de_seccion(): void
    {
        $data = $this->seedBase();
        $publish = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PATCH', 'uri' => '/api/checklists/'.$data['checklist']->id.'/versions/'.$data['version']->id, 'body' => ['status' => 'published']];
        $edit = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PUT', 'uri' => '/api/sections/'.$data['section']->id, 'body' => ['name' => 'Sección editada', 'display_order' => 1]];
        [$a, $b] = $this->runPair($publish, $edit);
        $this->assertNotSame(500, $a['status']);
        $this->assertNotSame(500, $b['status']);
    }

    public function test_publicacion_frente_a_vinculacion_normativa(): void
    {
        $data = $this->seedBase();
        $publish = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PATCH', 'uri' => '/api/checklists/'.$data['checklist']->id.'/versions/'.$data['version']->id, 'body' => ['status' => 'published']];
        $link = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'POST', 'uri' => '/api/questions/'.$data['question']->id.'/norm-requirements', 'body' => ['norm_requirement_id' => $data['requirement']->id]];
        [$a, $b] = $this->runPair($publish, $link);
        $this->assertNotSame(500, $a['status']);
        $this->assertNotSame(500, $b['status']);
    }

    public function test_publicacion_frente_a_modificacion_de_requisito(): void
    {
        $data = $this->seedBase();
        DB::table('question_norm_requirement')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $data['org']->id, 'question_id' => $data['question']->id, 'norm_requirement_id' => $data['requirement']->id]);
        $publish = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PATCH', 'uri' => '/api/checklists/'.$data['checklist']->id.'/versions/'.$data['version']->id, 'body' => ['status' => 'published']];
        $edit = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PUT', 'uri' => '/api/requirements/'.$data['requirement']->id, 'body' => ['code' => 'R2', 'description' => 'Modificado']];
        [$a, $b] = $this->runPair($publish, $edit);
        $this->assertNotSame(500, $a['status']);
        $this->assertNotSame(500, $b['status']);
    }

    public function test_publicacion_frente_a_desactivacion_de_norma(): void
    {
        $data = $this->seedBase();
        DB::table('question_norm_requirement')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $data['org']->id, 'question_id' => $data['question']->id, 'norm_requirement_id' => $data['requirement']->id]);
        $publish = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PATCH', 'uri' => '/api/checklists/'.$data['checklist']->id.'/versions/'.$data['version']->id, 'body' => ['status' => 'published']];
        $deactivate = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PATCH', 'uri' => '/api/norms/'.$data['norm']->id, 'body' => ['status' => false]];
        [$a, $b] = $this->runPair($publish, $deactivate);
        $this->assertNotSame(500, $a['status']);
        $this->assertNotSame(500, $b['status']);
    }

    public function test_publicacion_frente_a_desactivacion_de_checklist(): void
    {
        $data = $this->seedBase();
        $publish = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PATCH', 'uri' => '/api/checklists/'.$data['checklist']->id.'/versions/'.$data['version']->id, 'body' => ['status' => 'published']];
        $deactivate = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'PATCH', 'uri' => '/api/checklists/'.$data['checklist']->id, 'body' => ['status' => false]];
        [$a, $b] = $this->runPair($publish, $deactivate);
        $this->assertNotSame(500, $a['status']);
        $this->assertNotSame(500, $b['status']);
    }

    public function test_creacion_simultanea_de_versiones_duplicadas(): void
    {
        $data = $this->seedBase();
        $payload = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'POST', 'uri' => '/api/checklists/'.$data['checklist']->id.'/versions', 'body' => ['version' => '2.0']];
        [$a, $b] = $this->runPair($payload, $payload);
        $this->assertNotSame(500, $a['status']);
        $this->assertNotSame(500, $b['status']);
        $this->assertSame(1, DB::table('checklist_versions')->where('checklist_id', $data['checklist']->id)->where('version', '2.0')->count());
    }

    public function test_creacion_concurrente_con_display_order_duplicado(): void
    {
        $data = $this->seedBase();
        $first = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'POST', 'uri' => '/api/versions/'.$data['version']->id.'/sections', 'body' => ['name' => 'Sección A', 'display_order' => 9]];
        $second = ['user_id' => $data['user']->id, 'organization_id' => $data['org']->id, 'method' => 'POST', 'uri' => '/api/versions/'.$data['version']->id.'/sections', 'body' => ['name' => 'Sección B', 'display_order' => 9]];
        [$a, $b] = $this->runPair($first, $second);
        $this->assertNotSame(500, $a['status']);
        $this->assertNotSame(500, $b['status']);
        $this->assertSame(1, DB::table('checklist_sections')->where('checklist_version_id', $data['version']->id)->where('display_order', 9)->count());
    }
}
