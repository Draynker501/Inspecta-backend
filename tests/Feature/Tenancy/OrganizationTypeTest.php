<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizacion_tiene_tipo_client_por_defecto(): void
    {
        $organization = Organization::factory()->create();

        $this->assertSame(Organization::TYPE_CLIENT, $organization->type);
    }

    public function test_se_puede_crear_organizacion_operador(): void
    {
        $organization = Organization::create([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'name' => 'Consultora INSPECTA',
            'code' => 'INSPECTA-OP',
            'type' => Organization::TYPE_OPERATOR,
            'status' => true,
        ]);

        $this->assertSame(Organization::TYPE_OPERATOR, $organization->type);
    }

    public function test_organizacion_seeder_usa_tipo_client(): void
    {
        $this->seedOrganizations();

        $organization = Organization::where('code', 'INSP-01')->first();

        $this->assertNotNull($organization);
        $this->assertSame(Organization::TYPE_CLIENT, $organization->type);
    }

    private function seedOrganizations(): void
    {
        (new \Database\Seeders\OrganizationSeeder())->run();
    }
}
