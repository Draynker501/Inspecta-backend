<?php

namespace Tests\Feature\Api;

use App\Models\Area;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/**
 * Ejecutar solo con phpunit.mariadb.xml y una MariaDB 11.8 local efímera.
 * Esta prueba no debe apuntar a una base persistente.
 */
class AreasStoreMariaDbTest extends TestCase
{
    use TenantFixtures;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Prueba HTTP exclusiva de MariaDB.');
        }

        $this->assertSame('testing', app()->environment());
        $this->assertContains($connection->getConfig('host'), ['127.0.0.1', 'localhost']);
        $this->assertStringEndsWith('_test', $connection->getDatabaseName());
        $this->assertTrue(filter_var(getenv('TESTING_DB_ALLOW_EXTERNAL'), FILTER_VALIDATE_BOOLEAN));
        $this->assertMatchesRegularExpression('/(?:^|[^0-9])11\\.8\\.[0-9]+.*MariaDB/i', (string) $connection->selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    public function test_colision_del_indice_areas_plant_code_unique_se_traduce_a_422(): void
    {
        $organization = $this->clientOrganization('AREA-MARIA');
        $user = $this->admin($organization);
        $plant = $this->plant($organization, 'P-1');

        Event::listen('eloquent.creating: '.Area::class, function (Area $area): void {
            if ($area->name !== 'Carrera SQL') {
                return;
            }

            DB::table('areas')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $area->organization_id,
                'plant_id' => $area->plant_id,
                'name' => 'Carrera concurrente',
                'code' => $area->code,
                'status' => true,
            ]);
        });

        $this->actingAsApiInOrganization($user, $organization)
            ->postJson("/api/plants/{$plant->id}/areas", ['name' => 'Carrera SQL', 'code' => 'SQL-1'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('code');

        $this->assertSame(0, DB::table('areas')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'area.created')->count());
    }
}
