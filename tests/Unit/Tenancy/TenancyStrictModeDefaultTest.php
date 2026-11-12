<?php

namespace Tests\Unit\Tenancy;

use Tests\TestCase;

class TenancyStrictModeDefaultTest extends TestCase
{
    private array $savedEnv = [];

    private array $savedServer = [];

    private ?string $savedGetenv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedEnv = $_ENV;
        $this->savedServer = $_SERVER;
        $this->savedGetenv = getenv('TENANCY_STRICT_MODE') ?: null;

        unset($_ENV['TENANCY_STRICT_MODE']);
        unset($_SERVER['TENANCY_STRICT_MODE']);
        putenv('TENANCY_STRICT_MODE');
    }

    protected function tearDown(): void
    {
        $_ENV = $this->savedEnv;
        $_SERVER = $this->savedServer;

        if ($this->savedGetenv === null) {
            putenv('TENANCY_STRICT_MODE');
        } else {
            putenv('TENANCY_STRICT_MODE='.$this->savedGetenv);
        }

        parent::tearDown();
    }

    public function test_default_strict_mode_is_true(): void
    {
        $config = require base_path('config/tenancy.php');

        $this->assertTrue($config['strict_mode']);
    }
}
