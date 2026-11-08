<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

class WithoutTenantUsageTest extends TestCase
{
    public function test_sin_withouttenant_en_app_y_database_fuera_de_lista_permitida(): void
    {
        $allowed = ['app/Models/User.php'];

        $files = array_merge(
            $this->phpFiles('app'),
            $this->phpFiles('database')
        );

        $violations = [];

        foreach ($files as $file) {
            $relative = str_replace(base_path().'/', '', $file);

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if (str_contains($contents, '->withoutTenant(')) {
                $violations[] = $relative;
            }
        }

        $this->assertSame([], $violations, 'Uso no permitido de withoutTenant(): '.implode(', ', $violations));
    }

    public function test_sin_withouttenant_en_controladores(): void
    {
        $files = $this->phpFiles('app/Http/Controllers');

        $violations = [];

        foreach ($files as $file) {
            $relative = str_replace(base_path().'/', '', $file);
            $contents = file_get_contents($file);

            if (str_contains($contents, '->withoutTenant(')) {
                $violations[] = $relative;
            }
        }

        $this->assertSame([], $violations, 'withoutTenant() no está permitido en controladores: '.implode(', ', $violations));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $path = base_path($directory);

        if (! is_dir($path)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
