<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class MembershipIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    // --- Corrección 1: hook de doble escritura ---

    public function test_crear_user_por_factory_crea_membresia_activa(): void
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

    public function test_guardar_user_sin_cambiar_organization_id_no_modifica_membresia_revocada(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        // Crear y revocar membresía en otra organización
        $otra = $this->organization('ORG-B');
        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $otra->id,
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        // Guardar cambiando solo el nombre (organization_id no cambia)
        $user->name = 'Nombre modificado';
        $user->save();

        // La membresía revocada debe seguir revocada
        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $otra->id)
            ->first();

        $this->assertSame(OrganizationMembership::STATUS_REVOKED, $membership->status);
        $this->assertNotNull($membership->revoked_at);
    }

    public function test_cambiar_organization_id_a_organizacion_sin_membresia_la_crea_y_conserva_anterior(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        // La membresía en ORG-A ya existe por la factory
        $this->assertTrue(
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $orgA->id)
                ->exists()
        );

        $user->organization_id = $orgB->id;
        $user->save();

        // Se crea membresía activa en ORG-B
        $this->assertTrue(
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $orgB->id)
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->exists()
        );
        // Se conserva la membresía en ORG-A
        $this->assertTrue(
            OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $orgA->id)
                ->exists()
        );
        $this->assertSame(2, OrganizationMembership::where('user_id', $user->id)->count());
    }

    public function test_cambiar_organization_id_a_organizacion_con_membresia_revocada_la_deja_revocada(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        // Crear membresía revocada en ORG-B
        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'status' => OrganizationMembership::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $user->organization_id = $orgB->id;
        $user->save();

        // El hook nunca modifica el status de una membresía existente
        $membership = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $orgB->id)
            ->first();

        $this->assertSame(OrganizationMembership::STATUS_REVOKED, $membership->status);
        $this->assertNotNull($membership->revoked_at);
    }

    public function test_guardar_sin_cambios_no_crea_ni_modifica_membresias(): void
    {
        $organization = $this->organization('ORG-A');
        $user = $this->user($organization);

        $conteoAntes = OrganizationMembership::where('user_id', $user->id)->count();
        $statusAntes = OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->value('status');

        $user->save();

        $this->assertSame($conteoAntes, OrganizationMembership::where('user_id', $user->id)->count());
        $this->assertSame($statusAntes, OrganizationMembership::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->value('status'));
    }

    public function test_usuario_puede_tener_membresias_en_dos_organizaciones(): void
    {
        $orgA = $this->organization('ORG-A');
        $orgB = $this->organization('ORG-B');
        $user = $this->user($orgA);

        // Membresía en ORG-B creada explícitamente
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

    // --- Corrección 2: seeders sin membresías ---

    public function test_seeders_crean_membresia_activa_por_usuario(): void
    {
        $this->seed();

        $organizationId = \DB::table('organizations')
            ->where('code', 'INSP-01')
            ->value('id');

        $this->assertNotNull($organizationId);

        foreach (\DB::table('users')->where('organization_id', $organizationId)->get() as $user) {
            $this->assertTrue(
                \DB::table('organization_memberships')
                    ->where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->where('status', OrganizationMembership::STATUS_ACTIVE)
                    ->exists(),
                "Usuario {$user->id} no tiene membresía activa en su organización."
            );
        }
    }

    public function test_seeders_conteo_users_igual_a_membresias(): void
    {
        $this->seed();

        $organizationId = \DB::table('organizations')
            ->where('code', 'INSP-01')
            ->value('id');

        $usuarios = \DB::table('users')
            ->where('organization_id', $organizationId)
            ->count();

        $membresias = \DB::table('organization_memberships')
            ->where('organization_id', $organizationId)
            ->count();

        $this->assertSame($usuarios, $membresias);
    }

    public function test_seeders_cero_membresias_con_organizacion_distinta(): void
    {
        $this->seed();

        $cantidad = \DB::table('organization_memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereColumn('m.organization_id', '!=', 'u.organization_id')
            ->count();

        $this->assertSame(0, $cantidad);
    }

    public function test_seeders_ejecutados_dos_veces_no_duplican_membresias(): void
    {
        $this->seed();
        $this->seed();

        $cantidad = \DB::table('organization_memberships')->count();
        $usuarios = \DB::table('users')->count();

        // Cada usuario debe tener exactamente una membresía
        $this->assertSame($usuarios, $cantidad);
    }
}
