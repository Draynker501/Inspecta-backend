<?php

namespace Tests\Feature\Tenancy;

use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class MembershipIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_factory_crea_usuario_global_sin_membresias_automaticas(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);

        $this->assertSame(
            0,
            OrganizationMembership::where('user_id', $user->id)->count()
        );
    }

    public function test_usuario_puede_tener_membresias_en_dos_organizaciones(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->assertSame(
            2,
            OrganizationMembership::where('user_id', $user->id)->count()
        );

        $this->assertDatabaseHas('organization_memberships', [
            'user_id' => $user->id,
            'organization_id' => $orgA->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('organization_memberships', [
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);
    }

    public function test_guardar_usuario_no_modifica_una_membresia_revocada(): void
    {
        $organization = $this->organization('ORG-A');
        $otra = $this->organization('ORG-B');
        $user = $this->user($organization);

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $otra->id,
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $user->name = 'Nombre modificado';
        $user->save();

        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $otra->id)
            ->firstOrFail();

        $this->assertSame(
            OrganizationMembership::STATUS_REVOKED,
            $membership->status
        );

        $this->assertNotNull($membership->revoked_at);
    }

    public function test_guardar_usuario_sin_cambios_no_modifica_sus_membresias(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        $membresiasAntes = OrganizationMembership::where(
            'user_id',
            $user->id
        )->get(['organization_id', 'status', 'revoked_at'])->toArray();

        $user->save();

        $membresiasDespues = OrganizationMembership::where(
            'user_id',
            $user->id
        )->get(['organization_id', 'status', 'revoked_at'])->toArray();

        $this->assertSame($membresiasAntes, $membresiasDespues);
    }

    public function test_seeders_crean_membresia_activa_para_los_usuarios_demo(): void
    {
        $this->seed();

        $organizationId = DB::table('organizations')
            ->where('code', 'INSP-01')
            ->value('id');

        $this->assertNotNull($organizationId);

        $usuarios = DB::table('users')
            ->where('organization_id', $organizationId)
            ->get();

        $this->assertGreaterThan(0, $usuarios->count());

        foreach ($usuarios as $user) {
            $this->assertDatabaseHas('organization_memberships', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'status' => OrganizationMembership::STATUS_ACTIVE,
            ]);
        }
    }

    public function test_seeders_no_duplican_membresias_al_ejecutarse_dos_veces(): void
    {
        $this->seed();

        $organizationId = DB::table('organizations')
            ->where('code', 'INSP-01')
            ->value('id');

        $this->assertNotNull($organizationId);

        $cantidadPrimeraSiembra = DB::table('organization_memberships')
            ->where('organization_id', $organizationId)
            ->count();

        $this->seed();

        $cantidadSegundaSiembra = DB::table('organization_memberships')
            ->where('organization_id', $organizationId)
            ->count();

        $this->assertSame(
            $cantidadPrimeraSiembra,
            $cantidadSegundaSiembra
        );
    }
}
