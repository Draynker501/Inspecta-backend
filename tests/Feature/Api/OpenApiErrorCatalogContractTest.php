<?php

namespace Tests\Feature\Api;

use App\Support\Api\ApiErrorCode;
use App\Support\Tenancy\TenantErrorCode;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Prueba de contrato del catálogo de errores del archivo OpenAPI (D-16).
 *
 * Verifica que el contrato (openapi.yaml) esté sincronizado con el código:
 * (b) el enum de códigos del schema Error coincide exactamente con la unión
 *     de TenantErrorCode y ApiErrorCode (sin faltantes ni sobrantes);
 * (c) todas las referencias $ref internas del contrato se resuelven;
 * (d) cada operación implementada (route:list, prefijo api/) que exige
 *     autenticación documenta la respuesta 401.
 *
 * La versión del contrato (info.version 2.0.0) se comprueba en
 * Tests\Feature\Documentation\OpenApiContractTest.
 *
 * El parseo usa Symfony\Component\Yaml (dependencia de desarrollo añadida
 * específicamente para esta prueba; ver composer.json require-dev).
 */
class OpenApiErrorCatalogContractTest extends TestCase
{
    private array $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contract = Yaml::parseFile(base_path('openapi.yaml'));

        $this->assertIsArray($this->contract);
        $this->assertSame('3.0.3', $this->contract['openapi']);
    }

    public function test_el_enum_de_codigos_del_schema_error_coincide_con_la_aplicacion(): void
    {
        $error = $this->contract['components']['schemas']['Error'];

        $this->assertArrayHasKey('properties', $error);
        $this->assertArrayHasKey('code', $error['properties']);
        $this->assertArrayHasKey('$ref', $error['properties']['code'], 'Error.code debe reutilizar el schema ErrorCode.');

        $errorCode = $this->resolveRef($error['properties']['code']['$ref']);

        $this->assertArrayHasKey('enum', $errorCode);
        $this->assertNotEmpty($errorCode['enum']);

        $tenantCodes = array_values(
            (new ReflectionClass(TenantErrorCode::class))->getConstants()
        );

        $apiCodes = array_map(
            fn (ApiErrorCode $case) => $case->value,
            ApiErrorCode::cases()
        );

        $esperados = array_merge($tenantCodes, $apiCodes);
        $documentados = $errorCode['enum'];

        sort($esperados);
        sort($documentados);

        $this->assertSame(
            $esperados,
            $documentados,
            'El enum del contrato debe ser exactamente la unión de TenantErrorCode y ApiErrorCode: '
            .'sin faltantes ni sobrantes.'
        );
    }

    public function test_todas_las_referencias_internas_del_contrato_se_resuelven(): void
    {
        $refs = [];

        $this->collectRefs($this->contract, $refs);

        $this->assertNotEmpty($refs, 'El contrato debería contener referencias internas.');

        foreach (array_keys($refs) as $ref) {
            $resolved = $this->tryResolveRef($ref);

            $this->assertNotNull(
                $resolved,
                "El \$ref '{$ref}' no se resuelve dentro del contrato."
            );
        }
    }

    public function test_las_operaciones_implementadas_con_autenticacion_documentan_401(): void
    {
        Artisan::call('route:list', ['--json' => true]);

        $routes = json_decode((string) Artisan::output(), true);

        $this->assertIsArray($routes, 'route:list --json debería devolver un listado.');

        $paths = $this->contract['paths'];
        $this->assertNotEmpty($paths);

        $verificadas = 0;

        foreach ($routes as $route) {
            $uri = (string) ($route['uri'] ?? '');

            // Solo las rutas bajo api/ forman parte del contrato.
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $openApiPath = '/'.substr($uri, strlen('api/'));

            Assert::assertArrayHasKey(
                $openApiPath,
                $paths,
                "La ruta implementada {$uri} no está documentada en el contrato."
            );

            foreach (explode('|', (string) $route['method']) as $method) {
                $method = strtolower($method);

                if (! isset($paths[$openApiPath][$method])) {
                    continue;
                }

                $operation = $paths[$openApiPath][$method];

                $requiresAuth = ! array_key_exists('security', $operation)
                    || $operation['security'] !== [];

                if (! $requiresAuth) {
                    continue;
                }

                $verificadas++;

                Assert::assertArrayHasKey(
                    '401',
                    $operation['responses'] ?? [],
                    "{$method} {$openApiPath} exige autenticación y debe documentar la respuesta 401."
                );
            }
        }

        // Sanidad: al menos las rutas de /auth están implementadas.
        $this->assertGreaterThanOrEqual(3, $verificadas);
    }

    /**
     * @return mixed
     */
    private function resolveRef(string $ref)
    {
        $resolved = $this->tryResolveRef($ref);

        if ($resolved === null) {
            Assert::fail("El \$ref '{$ref}' no se resuelve dentro del contrato.");
        }

        return $resolved;
    }

    /**
     * @return mixed
     */
    private function tryResolveRef(string $ref)
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $current = $this->contract;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function collectRefs(mixed $node, array &$refs): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[$value] = true;
            } else {
                $this->collectRefs($value, $refs);
            }
        }
    }
}
