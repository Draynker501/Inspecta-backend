<?php

namespace Tests\Feature\Tenancy;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
        $this->assertFalse(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertFalse(Gate::forUser($user)->allows('manageMemberships'));
    }

    public function test_rol_inactivo_no_autoriza(): void
    {
        $user = $this->operatorUserWithRole(true);
        DB::table('roles')->where('name', config('tenancy.roles.platform_admin'))->update(['status' => false]);

        $this->assertFalse($user->hasPlatformRole());
        $this->assertFalse(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertFalse(Gate::forUser($user)->allows('manageMemberships'));
    }

    public function test_usuario_inactivo_tiene_rol_pero_no_autoriza(): void
    {
        $user = $this->operatorUserWithRole(true);
        $user->status = false;
        $user->save();

        $this->assertTrue($user->hasPlatformRole());
        $this->assertFalse(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertFalse(Gate::forUser($user)->allows('manageMemberships'));
    }

    public function test_rol_en_otra_organizacion_no_autoriza_y_restaura_contexto(): void
    {
        $operator = $this->operatorOrganization();
        $client = $this->clientOrganization();
        $user = $this->user($operator);
        OrganizationMembership::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'organization_id' => $client->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);
        app(TenantContext::class)->set($client->id);
        $role = $this->role($client, (string) config('tenancy.roles.platform_admin'));
        $user->assignRole($role);

        $this->assertFalse($user->hasPlatformRole());
        $this->assertSame($client->id, app(TenantContext::class)->id());
        $this->assertFalse(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertFalse(Gate::forUser($user)->allows('manageMemberships'));
        $this->assertSame($client->id, app(TenantContext::class)->id());
    }

    public function test_reactivacion_del_rol_restaura_autorizacion(): void
    {
        $user = $this->operatorUserWithRole(true);
        $role = DB::table('roles')->where('name', config('tenancy.roles.platform_admin'));
        $role->update(['status' => false]);
        $this->assertFalse($user->hasPlatformRole());
        $this->assertFalse(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertFalse(Gate::forUser($user)->allows('manageMemberships'));

        $role->update(['status' => true]);
        $this->assertTrue($user->hasPlatformRole());
        $this->assertTrue(Gate::forUser($user)->allows('viewMemberships'));
        $this->assertTrue(Gate::forUser($user)->allows('manageMemberships'));
    }
}
