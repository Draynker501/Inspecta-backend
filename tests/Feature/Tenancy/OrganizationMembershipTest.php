<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class OrganizationMembershipTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_crear_membresia_activa_por_defecto(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        $membership = OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
        ])->fresh();

        $this->assertSame(OrganizationMembership::STATUS_ACTIVE, $membership->status);
        $this->assertNull($membership->revoked_at);
        $this->assertTrue($membership->isActive());
    }

    public function test_unico_impide_duplicar_user_id_organization_id(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_fk_rechaza_usuario_inexistente(): void
    {
        $organization = $this->organization('ORG-A');

        $this->expectException(\Illuminate\Database\QueryException::class);

        OrganizationMembership::create([
            'user_id' => (string) \Illuminate\Support\Str::uuid7(),
            'organization_id' => $organization->id,
        ]);
    }

    public function test_fk_rechaza_organizacion_inexistente(): void
    {
        $user = User::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => (string) \Illuminate\Support\Str::uuid7(),
        ]);
    }

    public function test_usuario_tiene_relacion_memberships(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        $membership = OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
        ]);

        $this->assertTrue($user->memberships()->whereKey($membership->id)->exists());
        $this->assertSame($organization->id, $user->memberships()->first()->organization_id);
    }
}
