<?php

namespace Tests\Feature\Tenancy;

use App\Models\Area;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * Demuestra el aislamiento extremo a extremo con rutas de prueba:
 * middleware api.tenant -> TenantContext -> global scope -> policy.
 *
 * No se implementan controladores de dominio; las rutas viven solo en la
 * prueba y usan exactamente la infraestructura que usarán los módulos M2+.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/tenant-test')->middleware('api.tenant')->group(function (): void {
            Route::get('plants', function () {
                return response()->json([
                    'data' => Plant::query()
                        ->orderBy('code')
                        ->get(['id', 'code', 'name', 'organization_id']),
                ]);
            });

            Route::post('plants', function (Request $request) {
                Gate::authorize('create', Plant::class);

                $plant = Plant::create(['id' => (string) Str::uuid7()] + $request->validate([
                    'name' => ['required', 'string', 'max:150'],
                    'code' => ['required', 'string', 'max:50'],
                    'organization_id' => ['nullable', 'uuid'],
                ]));

                return response()->json([
                    'data' => $plant->only(['id', 'code', 'name', 'organization_id']),
                ], 201);
            });

            Route::get('plants/{plant}', function (Plant $plant) {
                Gate::authorize('view', $plant);

                return response()->json([
                    'data' => $plant->only(['id', 'code', 'name', 'organization_id']),
                ]);
            });

            Route::patch('plants/{plant}', function (Request $request, Plant $plant) {
                Gate::authorize('update', $plant);

                $plant->update($request->validate([
                    'name' => ['required', 'string', 'max:150'],
                ]));

                return response()->json(['data' => $plant->only(['id', 'name'])]);
            });

            Route::delete('plants/{plant}', function (Plant $plant) {
                Gate::authorize('delete', $plant);

                $plant->delete();

                return response()->noContent();
            });

            Route::get('plants/{plant}/areas', function (Plant $plant) {
                Gate::authorize('view', $plant);

                return response()->json([
                    'data' => $plant->areas()
                        ->orderBy('code')
                        ->get(['id', 'code', 'organization_id']),
                ]);
            });

            Route::get('areas/{area}', function (Area $area) {
                Gate::authorize('view', $area);

                return response()->json(['data' => ['id' => $area->id]]);
            });

            Route::post('areas', function (Request $request) {
                Gate::authorize('create', Area::class);

                $area = Area::create(['id' => (string) Str::uuid7()] + $request->validate([
                    'plant_id' => ['required', 'uuid'],
                    'name' => ['required', 'string', 'max:150'],
                    'code' => ['required', 'string', 'max:50'],
                    'organization_id' => ['nullable', 'uuid'],
                ]));

                return response()->json([
                    'data' => $area->only(['id', 'code', 'organization_id']),
                ], 201);
            });

            Route::get('lines/{line}', function (ProductionLine $line) {
                Gate::authorize('view', $line);

                return response()->json(['data' => ['id' => $line->id]]);
            });
        });
    }

    public function test_index_solo_devuelve_recursos_de_la_organizacion_del_usuario(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $adminA = $this->admin($orgA);

        $this->plant($orgA, 'A-01');
        $this->plant($orgA, 'A-02');
        $this->plant($orgB, 'B-01');

        $this->actingAsApi($adminA)
            ->getJson('/api/tenant-test/plants')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['code' => 'A-01'])
            ->assertJsonFragment(['code' => 'A-02'])
            ->assertJsonMissing(['code' => 'B-01']);
    }

    public function test_show_de_recurso_ajeno_devuelve_404_generico(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $adminA = $this->admin($orgA);
        $plantB = $this->plant($orgB, 'B-01');

        $this->actingAsApi($adminA)
            ->getJson("/api/tenant-test/plants/{$plantB->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Recurso no encontrado.')
            ->assertJsonMissing(['code' => 'B-01']);
    }

    public function test_update_de_recurso_ajeno_devuelve_404_y_no_modifica(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $adminA = $this->admin($orgA);
        $plantB = $this->plant($orgB, 'B-01');

        $this->actingAsApi($adminA)
            ->patchJson("/api/tenant-test/plants/{$plantB->id}", ['name' => 'Planta secuestrada'])
            ->assertNotFound();

        $this->assertDatabaseHas('plants', [
            'id' => $plantB->id,
            'name' => 'Planta B-01',
        ]);
    }

    public function test_destroy_de_recurso_ajeno_devuelve_404_y_no_elimina(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $adminA = $this->admin($orgA);
        $plantB = $this->plant($orgB, 'B-01');

        $this->actingAsApi($adminA)
            ->deleteJson("/api/tenant-test/plants/{$plantB->id}")
            ->assertNotFound();

        $this->assertSame(0, DB::table('plants')
            ->where('id', $plantB->id)
            ->whereNotNull('deleted_at')
            ->count());
    }

    public function test_no_puede_crear_manipulando_organization_id(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $adminA = $this->admin($orgA);

        $this->actingAsApi($adminA)
            ->postJson('/api/tenant-test/plants', [
                'name' => 'Planta intrusa',
                'code' => 'X-01',
                'organization_id' => $orgB->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La operación intenta escribir datos de otra organización.');

        $this->assertDatabaseMissing('plants', ['code' => 'X-01']);
    }

    public function test_crear_sin_organization_id_usa_la_organizacion_del_contexto(): void
    {
        $orgA = $this->organization('ORG-A');
        $adminA = $this->admin($orgA);

        $this->actingAsApi($adminA)
            ->postJson('/api/tenant-test/plants', [
                'name' => 'Planta nueva',
                'code' => 'A-99',
            ])
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $orgA->id);

        $this->assertDatabaseHas('plants', [
            'code' => 'A-99',
            'organization_id' => $orgA->id,
        ]);
    }

    public function test_area_con_planta_de_otra_organizacion_es_rechazada(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $adminA = $this->admin($orgA);
        $plantB = $this->plant($orgB, 'B-01');

        $this->actingAsApi($adminA)
            ->postJson('/api/tenant-test/areas', [
                'plant_id' => $plantB->id,
                'name' => 'Área intrusa',
                'code' => 'X-AREA',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                "El campo 'plant_id' referencia un recurso inexistente o de otra organización."
            );

        $this->assertDatabaseMissing('areas', ['code' => 'X-AREA']);
    }

    public function test_relaciones_anidadas_no_filtran_recursos_ajenos(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $adminA = $this->admin($orgA);

        $plantA = $this->plant($orgA, 'A-01');
        $this->area($plantA, 'A-AREA');
        $areaB = $this->area($this->plant($orgB, 'B-01'), 'B-AREA');

        $this->actingAsApi($adminA)
            ->getJson("/api/tenant-test/plants/{$plantA->id}/areas")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['code' => 'A-AREA'])
            ->assertJsonMissing(['code' => 'B-AREA']);

        $this->actingAsApi($adminA)
            ->getJson("/api/tenant-test/areas/{$areaB->id}")
            ->assertNotFound();
    }

    public function test_linea_de_produccion_ajena_no_es_accesible(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $adminA = $this->admin($orgA);
        $lineB = $this->productionLine(
            $this->area($this->plant($orgB, 'B-01'), 'B-AREA'),
            'B-LINE'
        );

        $this->actingAsApi($adminA)
            ->getJson("/api/tenant-test/lines/{$lineB->id}")
            ->assertNotFound();
    }

    public function test_admin_puede_actualizar_y_eliminar_su_propio_recurso(): void
    {
        $orgA = $this->organization('ORG-A');
        $adminA = $this->admin($orgA);
        $plantA = $this->plant($orgA, 'A-01');

        $this->actingAsApi($adminA)
            ->patchJson("/api/tenant-test/plants/{$plantA->id}", ['name' => 'Planta actualizada'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Planta actualizada');

        $this->actingAsApi($adminA)
            ->deleteJson("/api/tenant-test/plants/{$plantA->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('plants', ['id' => $plantA->id]);
    }

    public function test_inspector_solo_ve_plantas_asignadas(): void
    {
        $orgA = $this->organization('ORG-A');
        $inspector = $this->user($orgA);
        $inspector->assignRole($this->role($orgA, (string) config('tenancy.roles.inspector')));

        $assigned = $this->plant($orgA, 'A-01');
        $unassigned = $this->plant($orgA, 'A-02');
        $inspector->assignPlant($assigned);

        $this->actingAsApi($inspector)
            ->getJson("/api/tenant-test/plants/{$assigned->id}")
            ->assertOk();

        $this->actingAsApi($inspector)
            ->getJson("/api/tenant-test/plants/{$unassigned->id}")
            ->assertForbidden();
    }

    public function test_inspector_no_puede_crear_catalogo(): void
    {
        $orgA = $this->organization('ORG-A');
        $inspector = $this->user($orgA);
        $inspector->assignRole($this->role($orgA, (string) config('tenancy.roles.inspector')));

        $this->actingAsApi($inspector)
            ->postJson('/api/tenant-test/plants', [
                'name' => 'Planta no autorizada',
                'code' => 'A-77',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('plants', ['code' => 'A-77']);
    }

    public function test_usuario_inactivo_no_accede(): void
    {
        $orgA = $this->organization('ORG-A');
        $adminA = $this->admin($orgA);
        $adminA->forceFill(['status' => false])->save();

        $this->actingAsApi($adminA)
            ->getJson('/api/tenant-test/plants')
            ->assertForbidden()
            ->assertJsonPath('message', 'El usuario está inactivo. Contacte al administrador.');
    }

    public function test_organizacion_inactiva_no_otorga_contexto(): void
    {
        $orgA = $this->organization('ORG-A', ['status' => false]);
        $adminA = $this->admin($orgA);

        $this->actingAsApi($adminA)
            ->getJson('/api/tenant-test/plants')
            ->assertForbidden()
            ->assertJsonPath('message', 'La organización está inactiva. Contacte al administrador.');

        $this->actingAsApi($adminA)
            ->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'La organización está inactiva. Contacte al administrador.');
    }

    public function test_organizacion_eliminada_no_otorga_contexto(): void
    {
        $orgA = $this->organization('ORG-A');
        $adminA = $this->admin($orgA);
        $orgA->delete();

        $this->actingAsApi($adminA)
            ->getJson('/api/tenant-test/plants')
            ->assertForbidden()
            ->assertJsonPath('message', 'La organización está inactiva. Contacte al administrador.');
    }

    public function test_sin_token_no_hay_acceso(): void
    {
        $this->getJson('/api/tenant-test/plants')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'No autenticado. Token de acceso ausente.');
    }

    public function test_logout_sigue_disponible_aunque_la_organizacion_este_inactiva(): void
    {
        $orgA = $this->organization('ORG-A', ['status' => false]);
        $adminA = $this->admin($orgA);

        $this->actingAsApi($adminA)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Sesión cerrada correctamente.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_el_contexto_no_sobrevive_al_final_de_la_peticion(): void
    {
        $orgA = $this->organization('ORG-A');
        $adminA = $this->admin($orgA);

        app(TenantContext::class)->set($orgA->id);

        $this->actingAsApi($adminA)
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->assertNull(app(TenantContext::class)->id());
    }
}
