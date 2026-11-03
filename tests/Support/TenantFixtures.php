<?php

namespace Tests\Support;

use App\Models\Area;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Fixtures mínimas para pruebas multi-organización.
 *
 * Los nombres de rol son únicos a nivel global en el esquema actual, así
 * que cada prueba debe usar el nombre "Administrador" en una sola
 * organización (ver limitación de roles.name).
 */
trait TenantFixtures
{
    protected function organization(string $code, array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes + [
            'code' => $code,
            'name' => "Organización {$code}",
        ]);
    }

    protected function user(Organization $organization, array $attributes = []): User
    {
        $user = User::factory()->create();

        // Sobrescribir organization_id directamente (bypassa Fillable) con la
        // organización solicitada. Desde P3.2, la pertenencia se basa en
        // organization_memberships, no en users.organization_id.
        $user->organization_id = $organization->id;
        $user->save();

        // Crear membresía explícita.
        OrganizationMembership::firstOrCreate(
            ['user_id' => $user->id, 'organization_id' => $organization->id],
            [
                'id' => (string) Str::uuid7(),
                'status' => OrganizationMembership::STATUS_ACTIVE,
            ],
        );

        return $user;
    }

    protected function role(Organization $organization, string $name): Role
    {
        return Role::create([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'name' => $name,
            'description' => "Rol {$name} de {$organization->code}",
            'status' => true,
        ]);
    }

    protected function admin(Organization $organization): User
    {
        $user = $this->user($organization);

        // assignRole requiere TenantContext activo desde P3.2.
        app(TenantContext::class)->set($organization->id);

        $user->assignRole($this->role($organization, (string) config('tenancy.roles.admin')));

        return $user;
    }

    protected function plant(Organization $organization, string $code, array $attributes = []): Plant
    {
        return Plant::create($attributes + [
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'name' => "Planta {$code}",
            'code' => $code,
            'status' => true,
        ]);
    }

    protected function area(Plant $plant, string $code, array $attributes = []): Area
    {
        return Area::create($attributes + [
            'id' => (string) Str::uuid7(),
            'organization_id' => $plant->organization_id,
            'plant_id' => $plant->id,
            'name' => "Área {$code}",
            'code' => $code,
            'type' => 'produccion',
            'status' => true,
        ]);
    }

    protected function productionLine(Area $area, string $code, array $attributes = []): ProductionLine
    {
        return ProductionLine::create($attributes + [
            'id' => (string) Str::uuid7(),
            'organization_id' => $area->organization_id,
            'area_id' => $area->id,
            'name' => "Línea {$code}",
            'code' => $code,
            'status' => true,
        ]);
    }

    protected function actingAsApi(User $user): static
    {
        $token = $user->createToken('tests')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /**
     * Autentica como usuario y establece la cabecera X-Organization-Id.
     *
     * Requerido desde P3.1: el contexto de tenant se resuelve exclusivamente
     * mediante la cabecera y una membresía activa.
     */
    protected function actingAsApiInOrganization(User $user, Organization $organization): static
    {
        $token = $user->createToken('tests')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Organization-Id', $organization->id);
    }
}
