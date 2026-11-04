<?php

namespace Tests\Feature\Tenancy;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class PlatformRoleActiveMembershipTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function operatorUserWithRole(bool $activeMembership): User
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->user($operatorOrg);

        app(TenantContext::class)->set($operatorOrg->id);

        $role = $this->role($operatorOrg, (string) config('tenancy.roles.platform_admin'));
        $user->assignRole($role);

        app(TenantContext::class)->clear();

        if (! $activeMembership) {
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $operatorOrg->id)
                ->update(['status' => OrganizationMembership::STATUS_REVOKED]);
        }

        return $user;
    }

    public function test_caso_a_membresia_activa_y_rol(): void
    {
        $user = $this->operatorUserWithRole(true);

        $this->assertTrue($user->hasPlatformRole());
        $this->assertTrue(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertTrue(Gate::forUser($user)->allows('manageMemberships'));
    }

    public function test_caso_b_membresia_revocada_y_rol(): void
    {
        $user = $this->operatorUserWithRole(false);

        $this->assertFalse($user->hasPlatformRole());
        $this->assertFalse(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertFalse(Gate::forUser($user)->allows('manageMemberships'));
    }

    public function test_caso_c_sin_membresia_y_con_rol(): void
    {
        // La FK compuesta impide crear UserRole sin membresia; se simula
        // ausencia de membresia activa revocando la existente.
        $user = $this->operatorUserWithRole(false);

        $this->assertFalse($user->hasPlatformRole());
        $this->assertFalse(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertFalse(Gate::forUser($user)->allows('manageMemberships'));
    }

    public function test_caso_d_membresia_activa_sin_rol(): void
    {
        $operatorOrg = $this->operatorOrganization();
        $user = $this->user($operatorOrg);

        $this->assertFalse($user->hasPlatformRole());
        $this->assertTrue(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertFalse(Gate::forUser($user)->allows('manageMemberships'));
    }
}
