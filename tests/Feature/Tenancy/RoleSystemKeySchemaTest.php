<?php

namespace Tests\Feature\Tenancy;

use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class RoleSystemKeySchemaTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_roles_tiene_columna_system_key_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('roles', 'system_key'));
    }

    public function test_roles_personalizados_pueden_compartir_null(): void
    {
        $org = $this->clientOrganization('KEY-NULL');
        $first = $this->role($org, 'Custom-'.Str::random(8));
        $second = $this->role($org, 'Custom-'.Str::random(8));

        $this->assertNull(DB::table('roles')->where('id', $first->id)->value('system_key'));
        $this->assertNull(DB::table('roles')->where('id', $second->id)->value('system_key'));
    }

    public function test_system_key_no_es_asignable_por_create_y_no_se_actualiza_por_modelo(): void
    {
        $org = $this->clientOrganization('KEY-GUARD');
        $role = Role::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'name' => 'Guarded-'.Str::random(8),
            'status' => true,
            'system_key' => 'admin',
        ]);

        $this->assertNull($role->fresh()->system_key);

        $role->system_key = 'admin';
        $this->expectException(LogicException::class);
        $role->save();
    }

    public function test_system_key_duplicado_en_la_misma_organizacion_es_rechazado(): void
    {
        $org = $this->clientOrganization('KEY-DUP');
        $first = $this->role($org, 'First-'.Str::random(8));
        $second = $this->role($org, 'Second-'.Str::random(8));

        DB::table('roles')->where('id', $first->id)->update(['system_key' => 'admin']);

        $this->expectException(QueryException::class);

        DB::table('roles')->where('id', $second->id)->update(['system_key' => 'admin']);
    }

    public function test_system_key_puede_repetirse_entre_organizaciones(): void
    {
        $orgA = $this->clientOrganization('KEY-A');
        $orgB = $this->clientOrganization('KEY-B');
        $roleA = $this->role($orgA, 'Admin-'.Str::random(8));
        $roleB = $this->role($orgB, 'Admin-'.Str::random(8));

        DB::table('roles')->where('id', $roleA->id)->update(['system_key' => 'admin']);
        DB::table('roles')->where('id', $roleB->id)->update(['system_key' => 'admin']);

        $this->assertSame('admin', DB::table('roles')->where('id', $roleA->id)->value('system_key'));
        $this->assertSame('admin', DB::table('roles')->where('id', $roleB->id)->value('system_key'));
    }

    public function test_system_key_rechaza_valores_fuera_del_catalogo(): void
    {
        $org = $this->clientOrganization('KEY-INVALID');
        $role = $this->role($org, 'Invalid-'.Str::random(8));

        $this->expectException(QueryException::class);

        DB::table('roles')->where('id', $role->id)->update(['system_key' => 'owner']);
    }
}
