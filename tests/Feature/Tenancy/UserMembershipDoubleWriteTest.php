<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class UserMembershipDoubleWriteTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_crear_user_por_factory_crea_su_membresia(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        $this->assertTrue(
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->exists()
        );
    }

    public function test_cambiar_organization_id_asegura_nueva_y_conserva_anterior(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        // La membresía en ORG-A ya existe por la factory (doble escritura).
        $this->assertTrue(
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $orgA->id)
                ->exists()
        );

        $user->organization_id = $orgB->id;
        $user->save();

        $this->assertTrue(
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $orgB->id)
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->exists()
        );
        $this->assertTrue(
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $orgA->id)
                ->exists()
        );
        $this->assertSame(2, OrganizationMembership::where('user_id', $user->id)->count());
    }

    public function test_no_reactiva_membresia_revocada_al_cambiar_organization_id(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        // Crear y revocar membresía en ORG-B manualmente
        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $user->organization_id = $orgB->id;
        $user->save();

        // El hook nunca modifica el status de una membresía existente.
        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $orgB->id)
            ->first();

        $this->assertSame(OrganizationMembership::STATUS_REVOKED, $membership->status);
        $this->assertNotNull($membership->revoked_at);
        // Se crea la membresía en la nueva organización, no se reactiva la antigua.
        $this->assertSame(2, OrganizationMembership::where('user_id', $user->id)->count());
    }

    public function test_no_duplica_membresia_al_guardar_mismas_organizacion(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        $user->save();
        $user->save();

        $this->assertSame(
            1,
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->count()
        );
    }

    public function test_un_usuario_puede_tener_membresias_en_dos_organizaciones(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        // Membresía en ORG-B creada explícitamente (no por factory del user)
        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->assertSame(2, OrganizationMembership::where('user_id', $user->id)->count());
        $this->assertTrue(
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $orgA->id)
                ->exists()
        );
        $this->assertTrue(
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $orgB->id)
                ->exists()
        );
    }
}
