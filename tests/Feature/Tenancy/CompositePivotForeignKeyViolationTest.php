<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pruebas de violación de las FK compuestas en los pivotes user_roles,
 * user_plants y user_areas contra SQLite en memoria.
 *
 * Las FK compuestas (agregadas en P2.3) son:
 *   user_roles:   (user_id, organization_id) → organization_memberships
 *                 (role_id, organization_id)   → roles
 *   user_plants:  (user_id, organization_id) → organization_memberships
 *                 (plant_id, organization_id)  → plants
 *   user_areas:   (user_id, organization_id) → organization_memberships
 *                 (area_id, organization_id)   → areas
 *
 * SQLite aplica estas FK porque SQLiteConnector ejecuta
 * `PRAGMA foreign_keys = 1` (vendor/laravel/framework/src/Illuminate/Database/
 * Connectors/SQLiteConnector.php:94-100) y config/database.php:40 define
 * 'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true).
 *
 * Cada caso usa pares (usuario, recurso) distintos para que un único previo
 * (user_id, resource_id) no se dispare antes de la FK.
 */
class CompositePivotForeignKeyViolationTest extends TestCase
{
    use RefreshDatabase;

    private function createOrganization(string $code): array
    {
        $id = (string) Str::uuid7();
        DB::table('organizations')->insert([
            'id' => $id,
            'name' => "Organización {$code}",
            'code' => $code,
            'type' => Organization::TYPE_CLIENT,
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'code' => $code];
    }

    private function createUserWithMembershipIn(string $organizationId): array
    {
        $userId = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Usuario Test',
            'email' => 'test-' . Str::random(8) . '@example.test',
            'password' => '$2y$10$dummyhash',
            'organization_id' => $organizationId,
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('organization_memberships')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'status' => 'active',
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['user_id' => $userId];
    }

    private function addMembershipTo(string $userId, string $organizationId): void
    {
        DB::table('organization_memberships')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'status' => 'active',
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* user_roles                                                         */
    /* ------------------------------------------------------------------ */

    public function test_user_roles_rechaza_usuario_sin_membresia_en_organizacion_b(): void
    {
        $orgA = $this->createOrganization('ORG-A');
        $orgB = $this->createOrganization('ORG-B');

        $user = $this->createUserWithMembershipIn($orgA['id']);

        $roleId = (string) Str::uuid7();
        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'Rol B',
            'description' => 'Rol de ORG-B',
            'status' => true,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('user_roles')->count();

        try {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $user['user_id'],
                'role_id' => $roleId,
                'organization_id' => $orgB['id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Se esperaba QueryException por FK de membresía.');
        } catch (\Illuminate\Database\QueryException $e) {
            // Esperado: la FK de membresía rechaza la inserción.
        }

        $this->assertSame($before, DB::table('user_roles')->count());
    }

    public function test_user_roles_rechaza_recurso_de_otra_organizacion(): void
    {
        $orgA = $this->createOrganization('ORG-A');
        $orgB = $this->createOrganization('ORG-B');

        $user = $this->createUserWithMembershipIn($orgA['id']);

        $roleId = (string) Str::uuid7();
        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'Rol B',
            'description' => 'Rol de ORG-B',
            'status' => true,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('user_roles')->count();

        try {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $user['user_id'],
                'role_id' => $roleId,
                'organization_id' => $orgA['id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Se esperaba QueryException por FK de recurso.');
        } catch (\Illuminate\Database\QueryException $e) {
            // Esperado: la FK de recurso rechaza la inserción.
        }

        $this->assertSame($before, DB::table('user_roles')->count());
    }

    public function test_user_roles_acepta_usuario_con_membresia_en_ambas_organizaciones(): void
    {
        $orgA = $this->createOrganization('ORG-A');
        $orgB = $this->createOrganization('ORG-B');

        $user = $this->createUserWithMembershipIn($orgA['id']);
        $this->addMembershipTo($user['user_id'], $orgB['id']);

        $roleId = (string) Str::uuid7();
        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'Rol B',
            'description' => 'Rol de ORG-B',
            'status' => true,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('user_roles')->count();

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user['user_id'],
            'role_id' => $roleId,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($before + 1, DB::table('user_roles')->count());
    }

    /* ------------------------------------------------------------------ */
    /* user_plants                                                        */
    /* ------------------------------------------------------------------ */

    public function test_user_plants_rechaza_usuario_sin_membresia_en_organizacion_b(): void
    {
        $orgA = $this->createOrganization('ORG-A');
        $orgB = $this->createOrganization('ORG-B');

        $user = $this->createUserWithMembershipIn($orgA['id']);

        $plantId = (string) Str::uuid7();
        DB::table('plants')->insert([
            'id' => $plantId,
            'name' => 'Planta B',
            'code' => 'PLN-B',
            'address' => null,
            'description' => null,
            'status' => true,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('user_plants')->count();

        try {
            DB::table('user_plants')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $user['user_id'],
                'plant_id' => $plantId,
                'organization_id' => $orgB['id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Se esperaba QueryException por FK de membresía.');
        } catch (\Illuminate\Database\QueryException $e) {
            // Esperado.
        }

        $this->assertSame($before, DB::table('user_plants')->count());
    }

    public function test_user_plants_rechaza_recurso_de_otra_organizacion(): void
    {
        $orgA = $this->createOrganization('ORG-A');
        $orgB = $this->createOrganization('ORG-B');

        $user = $this->createUserWithMembershipIn($orgA['id']);

        $plantId = (string) Str::uuid7();
        DB::table('plants')->insert([
            'id' => $plantId,
            'name' => 'Planta B',
            'code' => 'PLN-B',
            'address' => null,
            'description' => null,
            'status' => true,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('user_plants')->count();

        try {
            DB::table('user_plants')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $user['user_id'],
                'plant_id' => $plantId,
                'organization_id' => $orgA['id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Se esperaba QueryException por FK de recurso.');
        } catch (\Illuminate\Database\QueryException $e) {
            // Esperado.
        }

        $this->assertSame($before, DB::table('user_plants')->count());
    }

    public function test_user_plants_acepta_usuario_con_membresia_en_ambas_organizaciones(): void
    {
        $orgA = $this->createOrganization('ORG-A');
        $orgB = $this->createOrganization('ORG-B');

        $user = $this->createUserWithMembershipIn($orgA['id']);
        $this->addMembershipTo($user['user_id'], $orgB['id']);

        $plantId = (string) Str::uuid7();
        DB::table('plants')->insert([
            'id' => $plantId,
            'name' => 'Planta B',
            'code' => 'PLN-B',
            'address' => null,
            'description' => null,
            'status' => true,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('user_plants')->count();

        DB::table('user_plants')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user['user_id'],
            'plant_id' => $plantId,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($before + 1, DB::table('user_plants')->count());
    }

    /* ------------------------------------------------------------------ */
    /* user_areas                                                         */
    /* ------------------------------------------------------------------ */

    public function test_user_areas_rechaza_usuario_sin_membresia_en_organizacion_b(): void
    {
        $orgA = $this->createOrganization('ORG-A');
        $orgB = $this->createOrganization('ORG-B');

        $user = $this->createUserWithMembershipIn($orgA['id']);

        $plantId = (string) Str::uuid7();
        DB::table('plants')->insert([
            'id' => $plantId,
            'name' => 'Planta B',
            'code' => 'PLN-B',
            'address' => null,
            'description' => null,
            'status' => true,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $areaId = (string) Str::uuid7();
        DB::table('areas')->insert([
            'id' => $areaId,
            'plant_id' => $plantId,
            'organization_id' => $orgB['id'],
            'name' => 'Área B',
            'code' => 'AR-B',
            'type' => 'produccion',
            'description' => null,
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('user_areas')->count();

        try {
            DB::table('user_areas')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $user['user_id'],
                'area_id' => $areaId,
                'organization_id' => $orgB['id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Se esperaba QueryException por FK de membresía.');
        } catch (\Illuminate\Database\QueryException $e) {
            // Esperado.
        }

        $this->assertSame($before, DB::table('user_areas')->count());
    }

    public function test_user_areas_rechaza_recurso_de_otra_organizacion(): void
    {
        $orgA = $this->createOrganization('ORG-A');
        $orgB = $this->createOrganization('ORG-B');

        $user = $this->createUserWithMembershipIn($orgA['id']);

        $plantId = (string) Str::uuid7();
        DB::table('plants')->insert([
            'id' => $plantId,
            'name' => 'Planta B',
            'code' => 'PLN-B',
            'address' => null,
            'description' => null,
            'status' => true,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $areaId = (string) Str::uuid7();
        DB::table('areas')->insert([
            'id' => $areaId,
            'plant_id' => $plantId,
            'organization_id' => $orgB['id'],
            'name' => 'Área B',
            'code' => 'AR-B',
            'type' => 'produccion',
            'description' => null,
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('user_areas')->count();

        try {
            DB::table('user_areas')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $user['user_id'],
                'area_id' => $areaId,
                'organization_id' => $orgA['id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Se esperaba QueryException por FK de recurso.');
        } catch (\Illuminate\Database\QueryException $e) {
            // Esperado.
        }

        $this->assertSame($before, DB::table('user_areas')->count());
    }

    public function test_user_areas_acepta_usuario_con_membresia_en_ambas_organizaciones(): void
    {
        $orgA = $this->createOrganization('ORG-A');
        $orgB = $this->createOrganization('ORG-B');

        $user = $this->createUserWithMembershipIn($orgA['id']);
        $this->addMembershipTo($user['user_id'], $orgB['id']);

        $plantId = (string) Str::uuid7();
        DB::table('plants')->insert([
            'id' => $plantId,
            'name' => 'Planta B',
            'code' => 'PLN-B',
            'address' => null,
            'description' => null,
            'status' => true,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $areaId = (string) Str::uuid7();
        DB::table('areas')->insert([
            'id' => $areaId,
            'plant_id' => $plantId,
            'organization_id' => $orgB['id'],
            'name' => 'Área B',
            'code' => 'AR-B',
            'type' => 'produccion',
            'description' => null,
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('user_areas')->count();

        DB::table('user_areas')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user['user_id'],
            'area_id' => $areaId,
            'organization_id' => $orgB['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($before + 1, DB::table('user_areas')->count());
    }

    /* ------------------------------------------------------------------ */
    /* RESTRICT y estado de membresía                                     */
    /* ------------------------------------------------------------------ */

    public function test_eliminar_membership_con_pivote_lanza_query_exception_restrict(): void
    {
        $orgA = $this->createOrganization('ORG-A');

        $user = $this->createUserWithMembershipIn($orgA['id']);

        $roleId = (string) Str::uuid7();
        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'Rol A',
            'description' => 'Rol de ORG-A',
            'status' => true,
            'organization_id' => $orgA['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user['user_id'],
            'role_id' => $roleId,
            'organization_id' => $orgA['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membershipId = DB::table('organization_memberships')
            ->where('user_id', $user['user_id'])
            ->where('organization_id', $orgA['id'])
            ->value('id');

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('organization_memberships')->where('id', $membershipId)->delete();
    }

    public function test_cambiar_membership_a_revocado_no_lanza_error(): void
    {
        $orgA = $this->createOrganization('ORG-A');

        $user = $this->createUserWithMembershipIn($orgA['id']);

        $roleId = (string) Str::uuid7();
        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'Rol A',
            'description' => 'Rol de ORG-A',
            'status' => true,
            'organization_id' => $orgA['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user['user_id'],
            'role_id' => $roleId,
            'organization_id' => $orgA['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membershipId = DB::table('organization_memberships')
            ->where('user_id', $user['user_id'])
            ->where('organization_id', $orgA['id'])
            ->value('id');

        // No debe lanzar excepción: las FK no dependen del status.
        DB::table('organization_memberships')->where('id', $membershipId)->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);

        $membership = DB::table('organization_memberships')->where('id', $membershipId)->first();
        $this->assertSame('revoked', $membership->status);
        $this->assertNotNull($membership->revoked_at);
    }
}
