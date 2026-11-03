<?php

namespace Tests\Feature\Tenancy;

use App\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class UserMembershipDoubleWriteTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_cambiar_organization_id_no_crea_membresia_ni_elimina_la_anterior(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        $this->assertDatabaseHas('organization_memberships', [
            'user_id' => $user->id,
            'organization_id' => $orgA->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        // organization_id es un campo de compatibilidad, no la fuente
        // de pertenencia organizacional.
        $user->organization_id = $orgB->id;
        $user->save();

        $this->assertDatabaseMissing('organization_memberships', [
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
        ]);

        $this->assertDatabaseHas('organization_memberships', [
            'user_id' => $user->id,
            'organization_id' => $orgA->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->assertSame(
            1,
            OrganizationMembership::where('user_id', $user->id)->count()
        );
    }

    public function test_cambiar_organization_id_no_reactiva_una_membresia_revocada(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $user->organization_id = $orgB->id;
        $user->save();

        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $orgB->id)
            ->firstOrFail();

        $this->assertSame(
            OrganizationMembership::STATUS_REVOKED,
            $membership->status
        );

        $this->assertNotNull($membership->revoked_at);

        $this->assertSame(
            2,
            OrganizationMembership::where('user_id', $user->id)->count()
        );
    }

    public function test_guardar_usuario_repetidamente_no_duplica_membresias(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        $cantidadInicial = OrganizationMembership::where(
            'user_id',
            $user->id
        )->count();

        $user->save();
        $user->save();

        $cantidadFinal = OrganizationMembership::where(
            'user_id',
            $user->id
        )->count();

        $this->assertSame($cantidadInicial, $cantidadFinal);

        $this->assertSame(1, $cantidadFinal);
    }
}
