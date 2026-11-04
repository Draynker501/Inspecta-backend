<?php

namespace Tests\Unit\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationMembershipFindActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_devuelve_membresia_activa(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $membership = OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $found = OrganizationMembership::findActive($user->id, $organization->id);

        $this->assertNotNull($found);
        $this->assertSame($membership->id, $found->id);
    }

    public function test_devuelve_null_si_esta_revocada(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $this->assertNull(OrganizationMembership::findActive($user->id, $organization->id));
    }

    public function test_devuelve_null_si_otra_organizacion(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $user = User::factory()->create();

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $organizationA->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->assertNull(OrganizationMembership::findActive($user->id, $organizationB->id));
    }

    public function test_devuelve_null_si_otro_usuario(): void
    {
        $organization = Organization::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        OrganizationMembership::create([
            'user_id' => $userA->id,
            'organization_id' => $organization->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->assertNull(OrganizationMembership::findActive($userB->id, $organization->id));
    }

    public function test_devuelve_null_con_valores_vacios(): void
    {
        $this->assertNull(OrganizationMembership::findActive('', 'org'));
        $this->assertNull(OrganizationMembership::findActive('user', ''));
        $this->assertNull(OrganizationMembership::findActive('', ''));
    }
}
