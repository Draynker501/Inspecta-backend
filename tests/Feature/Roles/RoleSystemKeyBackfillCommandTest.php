<?php

namespace Tests\Feature\Roles;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class RoleSystemKeyBackfillCommandTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    private function role(
        Organization $organization,
        string $id,
        string $name,
        ?string $systemKey = null
    ): void {
        DB::table('roles')->insert([
            'id' => $id,
            'organization_id' => $organization->id,
            'name' => $name,
            'description' => null,
            'status' => true,
            'system_key' => $systemKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function baseRoleId(Organization $organization, string $name): string
    {
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_DNS,
            $organization->id.':'.$name
        );
    }

    private function mappingFile(array $roles, int $version = 1): string
    {
        $path = tempnam(sys_get_temp_dir(), 'role-map-');
        file_put_contents($path, json_encode([
            'version' => $version,
            'roles' => $roles,
        ], JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function manifestPath(): string
    {
        $path = sys_get_temp_dir().'/role-manifest-'.Str::uuid7().'.json';
        $this->tempFiles[] = $path;

        return $path;
    }

    private function manifest(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function systemKey(string $roleId): ?string
    {
        return DB::table('roles')->where('id', $roleId)->value('system_key');
    }

    public function test_role_seeder_source_dry_run_and_apply_are_idempotent(): void
    {
        $org = $this->clientOrganization('BF-SEED');
        $adminId = $this->baseRoleId($org, 'Administrador');
        $supervisorId = $this->baseRoleId($org, 'Supervisor');
        $inspectorId = $this->baseRoleId($org, 'Inspector');
        $customId = (string) Str::uuid7();

        $this->role($org, $adminId, 'Administrador');
        $this->role($org, $supervisorId, 'Supervisor');
        $this->role($org, $inspectorId, 'Inspector');
        $this->role($org, $customId, 'Personalizado');

        $dryManifest = $this->manifestPath();
        $dryExit = Artisan::call('roles:backfill-system-key', [
            '--source' => 'role-seeder',
            '--organization' => $org->id,
            '--manifest' => $dryManifest,
        ]);

        $this->assertSame(0, $dryExit, Artisan::output());
        $this->assertNull($this->systemKey($adminId));
        $this->assertNull($this->systemKey($customId));
        $this->assertSame('dry_run', $this->manifest($dryManifest)['state']);
        $this->assertTrue(Str::isUuid($this->manifest($dryManifest)['run_id']));
        $this->assertSame(0600, fileperms($dryManifest) & 0777);
        $this->assertCount(3, $this->manifest($dryManifest)['changes']);

        $applyManifest = $this->manifestPath();
        $applyExit = Artisan::call('roles:backfill-system-key', [
            '--source' => 'role-seeder',
            '--organization' => $org->id,
            '--manifest' => $applyManifest,
            '--apply' => true,
        ]);

        $this->assertSame(0, $applyExit, Artisan::output());
        $this->assertSame('admin', $this->systemKey($adminId));
        $this->assertSame('supervisor', $this->systemKey($supervisorId));
        $this->assertSame('inspector', $this->systemKey($inspectorId));
        $this->assertNull($this->systemKey($customId));
        $this->assertSame('applied', $this->manifest($applyManifest)['state']);

        $repeatManifest = $this->manifestPath();
        $repeatExit = Artisan::call('roles:backfill-system-key', [
            '--source' => 'role-seeder',
            '--organization' => $org->id,
            '--manifest' => $repeatManifest,
            '--apply' => true,
        ]);

        $this->assertSame(0, $repeatExit, Artisan::output());
        $this->assertCount(0, $this->manifest($repeatManifest)['changes']);
        $this->assertCount(3, $this->manifest($repeatManifest)['noops']);
    }

    public function test_mapping_file_applies_only_selected_organization(): void
    {
        $orgA = $this->clientOrganization('BF-MAP-A');
        $orgB = $this->clientOrganization('BF-MAP-B');
        $roleAAdmin = (string) Str::uuid7();
        $roleASupervisor = (string) Str::uuid7();
        $roleBSupervisor = (string) Str::uuid7();

        $this->role($orgA, $roleAAdmin, 'Admin A');
        $this->role($orgA, $roleASupervisor, 'Supervisor A');
        $this->role($orgB, $roleBSupervisor, 'Supervisor B');

        $mapping = $this->mappingFile([
            ['organization_id' => $orgA->id, 'role_id' => $roleAAdmin, 'system_key' => 'admin'],
            ['organization_id' => $orgA->id, 'role_id' => $roleASupervisor, 'system_key' => 'supervisor'],
            ['organization_id' => $orgB->id, 'role_id' => $roleBSupervisor, 'system_key' => 'supervisor'],
        ]);

        $dryManifest = $this->manifestPath();
        $dryExit = Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file',
            '--mapping' => $mapping,
            '--organization' => $orgA->id,
            '--manifest' => $dryManifest,
        ]);
        $this->assertSame(0, $dryExit, Artisan::output());
        $this->assertNull($this->systemKey($roleAAdmin));
        $this->assertSame('dry_run', $this->manifest($dryManifest)['state']);
        $this->assertTrue(Str::isUuid($this->manifest($dryManifest)['run_id']));

        $manifest = $this->manifestPath();
        $exit = Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file',
            '--mapping' => $mapping,
            '--organization' => $orgA->id,
            '--manifest' => $manifest,
            '--apply' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('admin', $this->systemKey($roleAAdmin));
        $this->assertSame('supervisor', $this->systemKey($roleASupervisor));
        $this->assertNull($this->systemKey($roleBSupervisor));
        $this->assertSame('applied', $this->manifest($manifest)['state']);
        $this->assertCount(2, $this->manifest($manifest)['changes']);
    }

    public function test_mapping_file_rejects_duplicates_conflicts_and_missing_roles(): void
    {
        $org = $this->clientOrganization('BF-CONFLICT');
        $roleA = (string) Str::uuid7();
        $roleB = (string) Str::uuid7();
        $this->role($org, $roleA, 'Role A', 'inspector');
        $this->role($org, $roleB, 'Role B');

        $duplicate = $this->mappingFile([
            ['organization_id' => $org->id, 'role_id' => $roleA, 'system_key' => 'admin'],
            ['organization_id' => $org->id, 'role_id' => $roleA, 'system_key' => 'supervisor'],
        ]);
        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file', '--mapping' => $duplicate,
            '--organization' => $org->id, '--apply' => true,
        ]));
        $this->assertSame('inspector', $this->systemKey($roleA));

        $conflict = $this->mappingFile([
            ['organization_id' => $org->id, 'role_id' => $roleA, 'system_key' => 'admin'],
        ]);
        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file', '--mapping' => $conflict,
            '--organization' => $org->id, '--apply' => true,
        ]));
        $this->assertSame('inspector', $this->systemKey($roleA));

        $missing = $this->mappingFile([
            ['organization_id' => $org->id, 'role_id' => (string) Str::uuid7(), 'system_key' => 'admin'],
        ]);
        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file', '--mapping' => $missing,
            '--organization' => $org->id, '--apply' => true,
        ]));
        $this->assertNull($this->systemKey($roleB));
    }

    public function test_mapping_file_rejects_cross_tenant_role_and_invalid_schema(): void
    {
        $orgA = $this->clientOrganization('BF-X-A');
        $orgB = $this->clientOrganization('BF-X-B');
        $roleA = (string) Str::uuid7();
        $this->role($orgA, $roleA, 'Role A');

        $crossTenant = $this->mappingFile([
            ['organization_id' => $orgB->id, 'role_id' => $roleA, 'system_key' => 'admin'],
        ]);
        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file', '--mapping' => $crossTenant,
            '--organization' => $orgB->id, '--apply' => true,
        ]));
        $this->assertNull($this->systemKey($roleA));

        $invalidVersion = $this->mappingFile([
            ['organization_id' => $orgA->id, 'role_id' => $roleA, 'system_key' => 'admin'],
        ], 2);
        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file', '--mapping' => $invalidVersion,
            '--organization' => $orgA->id,
        ]));
    }

    public function test_apply_requires_organization_and_mapping_file_requires_mapping(): void
    {
        $org = $this->clientOrganization('BF-REQUIRED');
        $role = (string) Str::uuid7();
        $this->role($org, $role, 'Role');
        $mapping = $this->mappingFile([
            ['organization_id' => $org->id, 'role_id' => $role, 'system_key' => 'admin'],
        ]);

        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file', '--mapping' => $mapping, '--apply' => true,
        ]));
        $this->assertNull($this->systemKey($role));

        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file',
        ]));
    }

    public function test_manifest_supports_selective_reversion(): void
    {
        $org = $this->clientOrganization('BF-REVERT');
        $roleA = (string) Str::uuid7();
        $roleB = (string) Str::uuid7();
        $this->role($org, $roleA, 'Role A');
        $this->role($org, $roleB, 'Role B');
        $mapping = $this->mappingFile([
            ['organization_id' => $org->id, 'role_id' => $roleA, 'system_key' => 'admin'],
            ['organization_id' => $org->id, 'role_id' => $roleB, 'system_key' => 'supervisor'],
        ]);
        $manifestPath = $this->manifestPath();

        $this->assertSame(0, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file', '--mapping' => $mapping,
            '--organization' => $org->id, '--manifest' => $manifestPath, '--apply' => true,
        ]));

        $manifest = $this->manifest($manifestPath);
        $this->assertSame('applied', $manifest['state']);
        $this->assertCount(2, $manifest['changes']);
        $this->assertSame($org->id, $manifest['changes'][0]['organization_id']);
        $this->assertNull($manifest['changes'][0]['previous_system_key']);
        $this->assertSame('admin', $manifest['changes'][0]['system_key']);

        // Reversión manual y selectiva usando el manifiesto: solo roleA vuelve a NULL.
        DB::table('roles')->where('id', $roleA)->update(['system_key' => null]);

        $this->assertNull($this->systemKey($roleA));
        $this->assertSame('supervisor', $this->systemKey($roleB));
    }

    public function test_schema_without_system_key_fails_safely(): void
    {
        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('roles', 'system_key')
            ->andReturn(false);

        $org = $this->clientOrganization('BF-SCHEMA');

        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--source' => 'role-seeder',
            '--organization' => $org->id,
        ]));
    }

    public function test_existing_manifest_is_not_overwritten(): void
    {
        $org = $this->clientOrganization('BF-MAN-EXISTS');
        $role = (string) Str::uuid7();
        $this->role($org, $role, 'Role');
        $mapping = $this->mappingFile([
            ['organization_id' => $org->id, 'role_id' => $role, 'system_key' => 'admin'],
        ]);
        $manifest = $this->manifestPath();
        file_put_contents($manifest, 'original-manifest');

        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file', '--mapping' => $mapping,
            '--organization' => $org->id, '--manifest' => $manifest, '--apply' => true,
        ]));

        $this->assertSame('original-manifest', file_get_contents($manifest));
        $this->assertNull($this->systemKey($role));
    }

    public function test_invalid_manifest_path_stops_before_database_write(): void
    {
        $org = $this->clientOrganization('BF-BAD-PATH');
        $role = (string) Str::uuid7();
        $this->role($org, $role, 'Role');
        $mapping = $this->mappingFile([
            ['organization_id' => $org->id, 'role_id' => $role, 'system_key' => 'admin'],
        ]);

        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file', '--mapping' => $mapping,
            '--organization' => $org->id, '--manifest' => sys_get_temp_dir(), '--apply' => true,
        ]));

        $this->assertNull($this->systemKey($role));
    }

    public function test_transaction_error_before_commit_marks_uncertain_without_claiming_success(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('El disparador de fallo es específico de SQLite.');
        }

        $org = $this->clientOrganization('BF-TX-FAIL');
        $role = (string) Str::uuid7();
        $this->role($org, $role, 'Role');
        $mapping = $this->mappingFile([
            ['organization_id' => $org->id, 'role_id' => $role, 'system_key' => 'admin'],
        ]);
        $manifest = $this->manifestPath();

        DB::unprepared("CREATE TRIGGER reject_role_system_key BEFORE UPDATE OF system_key ON roles BEGIN SELECT RAISE(ABORT, 'blocked'); END");

        try {
            $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
                '--source' => 'mapping-file', '--mapping' => $mapping,
                '--organization' => $org->id, '--manifest' => $manifest, '--apply' => true,
            ]));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_role_system_key');
        }

        $this->assertNull($this->systemKey($role));
        $this->assertSame('uncertain', $this->manifest($manifest)['state']);
    }

    public function test_reconciliation_of_applied_manifest_is_read_only(): void
    {
        $org = $this->clientOrganization('BF-RECON-OK');
        $role = (string) Str::uuid7();
        $this->role($org, $role, 'Role');
        $mapping = $this->mappingFile([
            ['organization_id' => $org->id, 'role_id' => $role, 'system_key' => 'admin'],
        ]);
        $manifest = $this->manifestPath();

        $this->assertSame(0, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file', '--mapping' => $mapping,
            '--organization' => $org->id, '--manifest' => $manifest, '--apply' => true,
        ]));

        $before = file_get_contents($manifest);
        $this->assertSame(0, Artisan::call('roles:backfill-system-key', [
            '--reconcile' => $manifest,
        ]));
        $this->assertSame($before, file_get_contents($manifest));
        $this->assertSame('admin', $this->systemKey($role));
    }

    public function test_reconciliation_detects_later_modification_without_writing(): void
    {
        $org = $this->clientOrganization('BF-RECON-MOD');
        $role = (string) Str::uuid7();
        $this->role($org, $role, 'Role');
        $mapping = $this->mappingFile([
            ['organization_id' => $org->id, 'role_id' => $role, 'system_key' => 'admin'],
        ]);
        $manifest = $this->manifestPath();

        $this->assertSame(0, Artisan::call('roles:backfill-system-key', [
            '--source' => 'mapping-file', '--mapping' => $mapping,
            '--organization' => $org->id, '--manifest' => $manifest, '--apply' => true,
        ]));

        DB::table('roles')->where('id', $role)->update(['system_key' => 'supervisor']);

        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--reconcile' => $manifest,
        ]));
        $this->assertSame('supervisor', $this->systemKey($role));
    }

    public function test_prepared_manifest_with_applied_database_is_inconsistent(): void
    {
        $org = $this->clientOrganization('BF-RECON-STALE');
        $role = (string) Str::uuid7();
        $this->role($org, $role, 'Role');
        DB::table('roles')->where('id', $role)->update(['system_key' => 'admin']);

        $manifest = $this->manifestPath();
        file_put_contents($manifest, json_encode([
            'version' => 1,
            'state' => 'prepared',
            'changes' => [[
                'organization_id' => $org->id,
                'role_id' => $role,
                'system_key' => 'admin',
                'previous_system_key' => null,
            ]],
        ], JSON_THROW_ON_ERROR));

        $before = file_get_contents($manifest);
        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--reconcile' => $manifest,
        ]));
        $this->assertSame($before, file_get_contents($manifest));
        $this->assertSame('admin', $this->systemKey($role));
    }

    public function test_uncertain_manifest_requires_decision_even_if_database_matches(): void
    {
        $org = $this->clientOrganization('BF-RECON-UNCERTAIN');
        $role = (string) Str::uuid7();
        $this->role($org, $role, 'Role');
        DB::table('roles')->where('id', $role)->update(['system_key' => 'admin']);

        $manifest = $this->manifestPath();
        file_put_contents($manifest, json_encode([
            'version' => 1,
            'state' => 'uncertain',
            'changes' => [[
                'organization_id' => $org->id,
                'role_id' => $role,
                'system_key' => 'admin',
                'previous_system_key' => null,
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(1, Artisan::call('roles:backfill-system-key', [
            '--reconcile' => $manifest,
        ]));
        $this->assertSame('admin', $this->systemKey($role));
    }
}
