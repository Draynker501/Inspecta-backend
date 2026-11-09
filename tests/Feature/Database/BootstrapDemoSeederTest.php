<?php

namespace Tests\Feature\Database;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Policies\PlatformOperatorPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BootstrapDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_en_testing_siembra_todo(): void
    {
        $this->artisan('db:seed', ['--force' => true]);

        $this->assertTrue(Organization::where('code', 'INSPECTA-OP')->where('type', Organization::TYPE_OPERATOR)->exists());
        $this->assertTrue(Organization::where('code', 'INSP-01')->where('type', Organization::TYPE_CLIENT)->exists());
        $this->assertTrue(User::where('email', 'admin@inspecta-demo.com')->exists());
    }

    public function test_database_seeder_en_local_siembra_todo(): void
    {
        app()->detectEnvironment(fn () => 'local');

        try {
            $this->artisan('db:seed', ['--force' => true]);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        $this->assertTrue(Organization::where('code', 'INSP-01')->exists());
        $this->assertTrue(User::where('email', 'admin@inspecta-demo.com')->exists());
    }

    public function test_database_seeder_en_production_solo_arranque(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('db:seed', ['--force' => true]);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        $this->assertTrue(Organization::where('code', 'INSPECTA-OP')->where('type', Organization::TYPE_OPERATOR)->exists());
        $this->assertFalse(Organization::where('type', Organization::TYPE_CLIENT)->exists());
        $this->assertFalse(User::exists());
    }

    public function test_database_seeder_en_staging_solo_arranque(): void
    {
        app()->detectEnvironment(fn () => 'staging');

        try {
            $this->artisan('db:seed', ['--force' => true]);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        $this->assertTrue(Organization::where('code', 'INSPECTA-OP')->where('type', Organization::TYPE_OPERATOR)->exists());
        $this->assertFalse(Organization::where('type', Organization::TYPE_CLIENT)->exists());
    }

    public function test_idempotente_en_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('db:seed', ['--force' => true]);
            $this->artisan('db:seed', ['--force' => true]);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        $this->assertTrue(Organization::where('code', 'INSPECTA-OP')->exists());
        $this->assertFalse(Organization::where('type', Organization::TYPE_CLIENT)->exists());
    }

    public function test_demo_seeder_en_production_falla(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);

        try {
            $this->artisan('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_operadora_suficiente_para_platform_policy(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('db:seed', ['--force' => true]);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        $operator = Organization::where('code', 'INSPECTA-OP')->where('type', Organization::TYPE_OPERATOR)->firstOrFail();
        $role = Role::where('organization_id', $operator->id)->firstOrFail();

        $userId = (string) Str::uuid7();
        User::forceCreate([
            'id' => $userId,
            'name' => 'Operador Manual',
            'email' => 'operador-manual@example.test',
            'password' => bcrypt('secret'),
            'organization_id' => $operator->id,
            'status' => true,
        ]);

        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'organization_id' => $operator->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        UserRole::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'role_id' => $role->id,
            'organization_id' => $operator->id,
        ]);

        $user = User::findOrFail($userId);

        $this->assertTrue($user->isPlatformOperator());
        $this->assertTrue($user->hasPlatformRole());

        $policy = new PlatformOperatorPolicy;
        $this->assertTrue($policy->viewMemberships($user));
        $this->assertTrue($policy->manageMemberships($user));
    }
}
