<?php

namespace Tests\Feature\Bootstrap;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformOperatorTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_rechaza_el_alta_de_plataforma_si_el_codigo_pertenece_a_un_cliente(): void
    {
        Organization::factory()->create([
            'code' => 'INSPECTA-OP',
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
        ]);

        $this->artisan('inspecta:create-platform-user', [
            '--email' => 'op@test.test',
            '--name' => 'Operador',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
