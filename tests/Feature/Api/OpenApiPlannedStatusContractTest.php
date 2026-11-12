<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiPlannedStatusContractTest extends TestCase
{
    private function spec(): array
    {
        return Yaml::parseFile(base_path('openapi.yaml'));
    }

    /**
     * Rutas del contrato (normalizadas: {param} -> {}) que están registradas.
     *
     * @return array<string, bool>
     */
    private function implementedContractPaths(): array
    {
        $set = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $contract = '/'.substr($uri, 4);
            $contract = preg_replace('/\{[^}]+\}/', '{}', $contract);

            $set[$contract] = true;
        }

        return $set;
    }

    public function test_los_paths_implementados_no_tienen_x_status_planned(): void
    {
        $spec = $this->spec();
        $implemented = $this->implementedContractPaths();

        $violations = [];

        foreach ($spec['paths'] ?? [] as $path => $item) {
            $normalized = preg_replace('/\{[^}]+\}/', '{}', $path);

            if (! isset($implemented[$normalized])) {
                continue;
            }

            if (isset($item['x-status']) && $item['x-status'] === 'planned') {
                $violations[] = $path;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Paths implementados marcados como planned: '.implode(', ', $violations)
        );
    }

    public function test_los_paths_no_implementados_tienen_x_status_planned(): void
    {
        $spec = $this->spec();
        $implemented = $this->implementedContractPaths();

        $violations = [];

        foreach ($spec['paths'] ?? [] as $path => $item) {
            $normalized = preg_replace('/\{[^}]+\}/', '{}', $path);

            if (isset($implemented[$normalized])) {
                continue;
            }

            if (! isset($item['x-status']) || $item['x-status'] !== 'planned') {
                $violations[] = $path;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Paths no implementados sin x-status: planned: '.implode(', ', $violations)
        );
    }
}
