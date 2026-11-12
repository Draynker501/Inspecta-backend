<?php

namespace Tests\Feature\Tenancy;

use App\Http\Controllers\TenantController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Route;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * Controlador temporal de dominio para pruebas de TenantController.
 */
class TcOkController extends TenantController
{
    public function __invoke()
    {
        return response()->json(['ok' => true]);
    }
}

/**
 * Pruebas de la clase base TenantController (P33-D07).
 *
 * Verifica que el middleware estático de HasMiddleware exija el contexto
 * organizacional activo tanto dentro como fuera del grupo api.tenant, y que
 * la prueba de arquitectura cubra todos los controladores de la aplicación.
 */
class TenantControllerTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // Ruta DENTRO del grupo api.tenant (contexto resuelto por middleware).
        Route::prefix('api/tc-test')->middleware('api.tenant')->group(function (): void {
            Route::get('ok', TcOkController::class);
        });

        // Ruta FUERA del grupo api.tenant (misma clase de controlador,
        // protegida solo por el middleware estático de TenantController).
        Route::get('api/tc-test/outside-ok', TcOkController::class);
    }

    public function test_controlador_dentro_de_grupo_api_tenant_devuelve_200(): void
    {
        $org = $this->organization('TC-A');
        $user = $this->user($org);

        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/tc-test/ok');

        $response->assertOk();
        $this->assertSame(['ok' => true], $response->json());
    }

    public function test_controlador_fuera_de_grupo_falla_cerrada_sin_contexto(): void
    {
        $org = $this->organization('TC-B');
        $user = $this->user($org);

        // Misma clase de controlador, pero la ruta no declara api.tenant.
        // El middleware estático de TenantController debe fallar cerrado.
        $response = $this->actingAsApiInOrganization($user, $org)
            ->getJson('/api/tc-test/outside-ok');

        $response->assertStatus(400);
        $response->assertJson(['code' => 'organization_required']);
    }

    public function test_dentro_de_grupo_sin_cabecera_conserva_code_de_contexto(): void
    {
        $org = $this->organization('TC-C');
        $user = $this->user($org);

        // Autenticado pero sin cabecera X-Organization-Id: ResolveTenantContext
        // (dentro de api.tenant) devuelve organization_required antes de llegar
        // al controlador, por lo que el code de contexto no se pisa.
        $token = $user->createToken('tests')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tc-test/ok');

        $response->assertStatus(400);
        $response->assertJson(['code' => 'organization_required']);
    }

    public function test_todos_los_controladores_de_dominio_extienden_tenant_controller(): void
    {
        $controllersDir = app_path('Http/Controllers');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($controllersDir, \FilesystemIterator::SKIP_DOTS)
        );

        $exceptions = [
            'App\Http\Controllers\Controller',
            'App\Http\Controllers\Auth\AuthController',
            'App\Http\Controllers\Auth\OrganizationController',
        ];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($controllersDir.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relative = str_replace(['/', '\\'], '\\', $relative);
            $dirPart = dirname($relative);
            $relativeWithoutExt = pathinfo($relative, PATHINFO_FILENAME);
            $className = 'App\Http\Controllers\\'.($dirPart === '.' ? '' : $dirPart.'\\').$relativeWithoutExt;

            if (! class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (in_array($className, $exceptions, true)) {
                continue;
            }

            $parent = $reflection->getParentClass();
            $parentName = $parent ? $parent->getName() : null;

            $this->assertSame(
                'App\Http\Controllers\TenantController',
                $parentName,
                "El controlador {$className} debe extender TenantController."
            );
        }
    }
}
