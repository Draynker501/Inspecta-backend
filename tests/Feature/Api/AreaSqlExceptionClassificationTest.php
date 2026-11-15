<?php

namespace Tests\Feature\Api;

use App\Models\Area;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDOException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * Excepciones simuladas en el evento de inserción; no ejecuta MariaDB.
 * El controlador real debe decidir si traduce cada QueryException.
 */
class AreaSqlExceptionClassificationTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_solo_traduce_el_indice_autorizado_en_errores_mariadb_simulados(): void
    {
        $org = $this->clientOrganization('CLASSIFIER');
        $user = $this->admin($org);
        $plant = $this->plant($org, 'P');
        config(['database.connections.area_simulated' => [
            'driver' => 'mariadb', 'host' => '127.0.0.1', 'database' => 'not_connected',
            'username' => 'unused', 'password' => '',
        ]]);

        $cases = [
            ['23000', 1062, "Duplicate entry 'X' for key 'areas_plant_code_unique'", true],
            ['23000', 1062, "Duplicate entry 'X' for key 'areas.areas_plant_code_unique'", true],
            ['23000', 1062, "Duplicate entry 'X' for key 'db.areas.areas_plant_code_unique'", true],
            ['23000', 1062, "Duplicate entry 'X' for key 'other_areas_plant_code_unique'", false],
            ['23000', 1062, "Duplicate entry 'X' for key 'areas_plant_code_unique_other'", false],
            ['23000', 1062, "Duplicate entry 'X' for key 'PRIMARY'", false],
            ['23000', 1452, 'Cannot add or update a child row: foreign key constraint fails', false],
            ['HY000', 1205, 'Lock wait timeout exceeded', false],
            ['HY000', 1062, "Duplicate entry 'X' for key 'areas_plant_code_unique'", false],
            ['23000', 1452, "Duplicate entry 'X' for key 'areas_plant_code_unique'", false],
        ];

        $info = [];
        Event::listen('eloquent.creating: '.Area::class, function () use (&$info): void {
            $previous = new PDOException('Simulated SQL exception');
            $previous->errorInfo = $info;

            throw new QueryException('area_simulated', 'simulated insert', [], $previous);
        });

        $this->actingAsApiInOrganization($user, $org);
        foreach ($cases as [$state, $native, $message, $duplicate]) {
            $info = [$state, $native, $message];
            $response = $this->postJson("/api/plants/{$plant->id}/areas", ['name' => 'A', 'code' => 'A']);
            if ($duplicate) {
                $response->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('code');
            } else {
                $response->assertServerError();
                $this->assertStringNotContainsString($message, $response->getContent());
            }
        }

        $this->assertSame(0, DB::table('areas')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'area.created')->count());
    }
}
