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
     * Rutas implementadas por método y path normalizado.
     *
     * @return array<string, bool>
     */
    private function implementedOperations(): array
    {
        $set = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $contract = '/'.substr($uri, 4);
            $contract = preg_replace('/\{[^}]+\}/', '{}', $contract);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $set[strtolower($method).' '.$contract] = true;
            }
        }

        return $set;
    }

    private function isPlanned(array $operation, ?string $pathStatus): bool
    {
        if (isset($operation['x-status'])) {
            return $operation['x-status'] === 'planned';
        }

        return $pathStatus === 'planned';
    }

    public function test_los_paths_implementados_no_tienen_x_status_planned(): void
    {
        $spec = $this->spec();
        $implemented = $this->implementedOperations();

        $violations = [];

        foreach ($spec['paths'] ?? [] as $path => $item) {
            $normalized = preg_replace('/\{[^}]+\}/', '{}', $path);
            $pathStatus = $item['x-status'] ?? null;

            foreach ($item as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                if (! isset($implemented[$method.' '.$normalized])) {
                    continue;
                }

                if ($this->isPlanned($operation, $pathStatus)) {
                    $violations[] = strtoupper($method).' '.$path;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Operaciones implementadas marcadas como planned: '.implode(', ', $violations)
        );
    }

    public function test_los_paths_no_implementados_tienen_x_status_planned(): void
    {
        $spec = $this->spec();
        $implemented = $this->implementedOperations();

        $violations = [];

        foreach ($spec['paths'] ?? [] as $path => $item) {
            $normalized = preg_replace('/\{[^}]+\}/', '{}', $path);
            $pathStatus = $item['x-status'] ?? null;

            foreach ($item as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                if (isset($implemented[$method.' '.$normalized])) {
                    continue;
                }

                if (! $this->isPlanned($operation, $pathStatus)) {
                    $violations[] = strtoupper($method).' '.$path;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Operaciones no implementadas sin x-status: planned: '.implode(', ', $violations)
        );
    }
}
