<?php

namespace Tests\Feature\Documentation;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiRouteCorrespondenceTest extends TestCase
{
    private function implementedOpenApiOperations(): array
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));
        $operations = [];

        foreach ($spec['paths'] ?? [] as $path => $methods) {
            if (is_array($methods) && ($methods['x-status'] ?? null) === 'planned') {
                continue;
            }
            foreach ($methods as $method => $operation) {
                if (! in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }
                if (is_array($operation) && ($operation['x-status'] ?? null) === 'planned') {
                    continue;
                }
                $operations[] = strtoupper($method).' api'.rtrim($path, '/');
            }
        }

        return array_values(array_unique($operations));
    }

    private function laravelApiOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $operations[] = $method.' '.$uri;
                }
            }
        }

        return array_values(array_unique($operations));
    }

    public function test_las_operaciones_implementadas_en_openapi_existen_en_laravel(): void
    {
        $openapi = $this->implementedOpenApiOperations();
        $laravel = $this->laravelApiOperations();

        $missing = array_values(array_diff($openapi, $laravel));

        $this->assertSame([], $missing, 'Operaciones OpenAPI implementadas ausentes en Laravel: '.implode(', ', $missing));
    }

    public function test_las_rutas_api_de_laravel_estan_documentadas_como_implementadas(): void
    {
        $openapi = $this->implementedOpenApiOperations();
        $laravel = $this->laravelApiOperations();

        $undocumented = array_values(array_diff($laravel, $openapi));

        $this->assertSame([], $undocumented, 'Rutas Laravel no documentadas como implementadas: '.implode(', ', $undocumented));
    }
}
