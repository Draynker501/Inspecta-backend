<?php

namespace Tests\Feature\Database;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSeederEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuarios_se_crean_en_testing(): void
    {
        $this->artisan('db:seed', ['--force' => true]);

        $this->assertTrue(User::where('email', 'admin@inspecta-demo.com')->exists());
    }

    public function test_usuarios_se_crean_en_local(): void
    {
        app()->detectEnvironment(fn () => 'local');

        $this->artisan('db:seed', ['--force' => true]);

        $this->assertTrue(User::where('email', 'admin@inspecta-demo.com')->exists());
    }

    public function test_no_usuarios_en_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('db:seed', ['--force' => true]);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        $this->assertFalse(User::where('email', 'admin@inspecta-demo.com')->exists());
        $this->assertFalse(User::where('email', 'inspector@inspecta-demo.com')->exists());
        $this->assertTrue(Organization::where('code', 'INSPECTA-OP')->where('type', Organization::TYPE_OPERATOR)->exists());
        $this->assertFalse(Organization::where('code', 'INSP-01')->exists());
        $this->assertFalse(Organization::where('code', 'ORG-02')->exists());
    }

    public function test_no_usuarios_en_staging(): void
    {
        app()->detectEnvironment(fn () => 'staging');

        try {
            $this->artisan('db:seed', ['--force' => true]);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        $this->assertFalse(User::where('email', 'admin@inspecta-demo.com')->exists());
        $this->assertTrue(Organization::where('code', 'INSPECTA-OP')->where('type', Organization::TYPE_OPERATOR)->exists());
        $this->assertFalse(Organization::where('code', 'INSP-01')->exists());
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

        $this->assertFalse(User::where('email', 'admin@inspecta-demo.com')->exists());
        $this->assertTrue(Organization::where('code', 'INSPECTA-OP')->where('type', Organization::TYPE_OPERATOR)->exists());
        $this->assertFalse(Organization::where('code', 'INSP-01')->exists());
    }
}
