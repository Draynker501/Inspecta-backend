<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Support\Tenancy\MembershipBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class MembershipBackfillTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_backfill_crea_membresia_para_usuario_sin_ella(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        $resultado = MembershipBackfill::run();

        $this->assertSame(1, $resultado['created']);
        $this->assertSame(1, $resultado['users']);
        $this->assertSame(0, $resultado['already']);

        $this->assertTrue(
            DB::table('organization_memberships')
                ->where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->where('status', 'active')
                ->exists()
        );
    }

    public function test_backfill_es_idempotente_no_duplica(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        $primero = MembershipBackfill::run();
        $segundo = MembershipBackfill::run();

        $this->assertSame(1, $primero['created']);
        $this->assertSame(0, $segundo['created']);
        $this->assertSame(1, $segundo['already']);
        $this->assertSame(
            1,
            DB::table('organization_memberships')
                ->where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->count()
        );
    }

    public function test_backfill_no_reactiva_membresia_revocada(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $resultado = MembershipBackfill::run();

        $this->assertSame(0, $resultado['created']);
        $this->assertSame(1, $resultado['already']);

        $membership = DB::table('organization_memberships')
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->first();

        $this->assertSame(OrganizationMembership::STATUS_REVOKED, $membership->status);
        $this->assertNotNull($membership->revoked_at);
    }

    public function test_backfill_devuelve_conteo_correcto_con_varios_usuarios(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');

        $userA1 = $this->user($orgA);
        $userA2 = $this->user($orgA);
        $userB = $this->user($orgB);

        // userA1 ya tiene membresía activa
        OrganizationMembership::create([
            'user_id' => $userA1->id,
            'organization_id' => $orgA->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        // userA2 tiene membresía revocada (no se debe tocar)
        OrganizationMembership::create([
            'user_id' => $userA2->id,
            'organization_id' => $orgA->id,
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $resultado = MembershipBackfill::run();

        $this->assertSame(3, $resultado['users']);
        $this->assertSame(1, $resultado['created']);  // solo userB
        $this->assertSame(2, $resultado['already']);  // userA1 y userA2 ya tienen fila
    }
}
