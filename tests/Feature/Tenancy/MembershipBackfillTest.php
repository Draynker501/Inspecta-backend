<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Support\Tenancy\MembershipBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MembershipBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function crearUsuarioSinMembresia(string $organizationId): array
    {
        $id = (string) Str::uuid7();

        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Usuario sin membresía',
            'email' => 'sin-membresia-'.Str::random(8).'@example.test',
            'password' => '$2y$10$dummyhashforbackfilltest',
            'organization_id' => $organizationId,
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'organization_id' => $organizationId];
    }

    public function test_backfill_crea_membresia_para_usuario_sin_ella(): void
    {
        $organization = Organization::factory()->create();
        $usuario = $this->crearUsuarioSinMembresia($organization->id);

        $resultado = MembershipBackfill::run();

        $this->assertSame(1, $resultado['created']);
        $this->assertSame(1, $resultado['users']);
        $this->assertSame(0, $resultado['already']);

        $this->assertTrue(
            DB::table('organization_memberships')
                ->where('user_id', $usuario['id'])
                ->where('organization_id', $organization->id)
                ->where('status', 'active')
                ->exists()
        );
    }

    public function test_backfill_es_idempotente_no_duplica(): void
    {
        $organization = Organization::factory()->create();
        $usuario = $this->crearUsuarioSinMembresia($organization->id);

        $primero = MembershipBackfill::run();
        $segundo = MembershipBackfill::run();

        $this->assertSame(1, $primero['created']);
        $this->assertSame(0, $segundo['created']);
        $this->assertSame(1, $segundo['already']);
        $this->assertSame(
            1,
            DB::table('organization_memberships')
                ->where('user_id', $usuario['id'])
                ->where('organization_id', $organization->id)
                ->count()
        );
    }

    public function test_backfill_no_reactiva_membresia_revocada(): void
    {
        $organization = Organization::factory()->create();
        $usuario = $this->crearUsuarioSinMembresia($organization->id);

        // Crear membresía revocada directamente en la BD
        DB::table('organization_memberships')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $usuario['id'],
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resultado = MembershipBackfill::run();

        $this->assertSame(0, $resultado['created']);
        $this->assertSame(1, $resultado['already']);

        $membership = DB::table('organization_memberships')
            ->where('user_id', $usuario['id'])
            ->where('organization_id', $organization->id)
            ->first();

        $this->assertSame(OrganizationMembership::STATUS_REVOKED, $membership->status);
        $this->assertNotNull($membership->revoked_at);
    }

    public function test_backfill_devuelve_conteo_correcto_con_varios_usuarios(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        // userA1: sin membresía previa → backfill debe crearla
        $userA1 = $this->crearUsuarioSinMembresia($orgA->id);
        // userA2: sin membresía previa → backfill debe crearla
        $userA2 = $this->crearUsuarioSinMembresia($orgA->id);
        // userB: con membresía activa previa → backfill ya existente
        $userB = $this->crearUsuarioSinMembresia($orgB->id);
        DB::table('organization_memberships')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userB['id'],
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resultado = MembershipBackfill::run();

        $this->assertSame(3, $resultado['users']);
        $this->assertSame(2, $resultado['created']);   // userA1 y userA2
        $this->assertSame(1, $resultado['already']);   // userB
    }
}
