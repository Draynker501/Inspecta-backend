<?php

namespace Tests\Support;

use App\Models\Area;
use App\Models\Organization;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Models\Role;
use App\Models\User;
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
        return User::factory()->create($attributes + [
            'organization_id' => $organization->id,
        ]);
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
}
