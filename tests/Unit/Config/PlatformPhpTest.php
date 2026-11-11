<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

class PlatformPhpTest extends TestCase
{
    private const COMPOSER_JSON = __DIR__.'/../../../composer.json';

    public function test_platform_php_is_set_and_matches_require_php_minimum(): void
    {
        $composer = json_decode(file_get_contents(self::COMPOSER_JSON), true);

        $this->assertArrayHasKey('config', $composer);
        $this->assertArrayHasKey('platform', $composer['config']);
        $this->assertArrayHasKey('php', $composer['config']['platform']);

        $platformPhp = $composer['config']['platform']['php'];
        $this->assertStringMatchesFormat('%d.%d.%d', $platformPhp);

        $requirePhp = $composer['require']['php'] ?? '';
        $this->assertStringStartsWith('^', $requirePhp);

        $minimum = $this->minimumVersion($requirePhp);
        $platformMajorMinor = $this->majorMinor($platformPhp);

        $this->assertSame($minimum, $platformMajorMinor,
            'config.platform.php major.minor debe coincidir con el mínimo de require.php');
    }

    private function minimumVersion(string $requirePhp): string
    {
        // "^8.4" -> "8.4"; "8.4" -> "8.4"
        $version = ltrim($requirePhp, '^>=<~ ');
        $parts = explode('.', $version);

        return $parts[0].'.'.($parts[1] ?? '0');
    }

    private function majorMinor(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0].'.'.($parts[1] ?? '0');
    }
}
