<?php

namespace Tests\Feature\Database;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperatorIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_operadora_es_unica_y_activa(): void
    {
        $this->seed();

        $operators = Organization::query()->where('type', Organization::TYPE_OPERATOR)->where('status', true)->whereNull('deleted_at')->get();

        $this->assertCount(1, $operators);
        $this->assertSame('INSPECTA-OP', $operators->first()->code);
    }

    public function test_ninguna_tabla_de_dominio_referencia_la_operadora(): void
    {
        $this->seed();

        $operatorId = DB::table('organizations')->where('type', 'operator')->value('id');

        foreach (['plants', 'areas', 'production_lines', 'checklists', 'norms'] as $table) {
            $this->assertSame(0, DB::table($table)->where('organization_id', $operatorId)->count());
        }
    }

    public function test_el_consultor_de_plataforma_tiene_membresia_en_operadora_y_clientes(): void
    {
        $this->seed();

        $user = User::where('email', 'consultor@inspecta.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isPlatformOperator());
        $this->assertTrue($user->hasPlatformRole());

        $clientCount = DB::table('organizations')->where('type', 'client')->count();
        $membershipCount = DB::table('organization_memberships')
            ->where('user_id', $user->id)
            ->count();

        $this->assertSame($clientCount + 1, $membershipCount);
    }
}
