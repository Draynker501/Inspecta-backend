<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $clientRoles = [
            ['name' => 'Administrador', 'description' => 'Acceso completo a todas las funciones del sistema'],
            ['name' => 'Inspector', 'description' => 'Puede realizar inspecciones y reportar incidencias'],
            ['name' => 'Supervisor', 'description' => 'Puede confirmar o rechazar inspecciones'],
        ];

        $clients = Organization::query()
            ->where('type', Organization::TYPE_CLIENT)
            ->where('status', true)
            ->whereNull('deleted_at')
            ->get();

        foreach ($clients as $organization) {
            foreach ($clientRoles as $role) {
                Role::updateOrCreate(
                    ['organization_id' => $organization->id, 'name' => $role['name']],
                    [
                        'id' => $this->deterministicId($organization->id, $role['name']),
                        'organization_id' => $organization->id,
                        'name' => $role['name'],
                        'description' => $role['description'],
                        'status' => true,
                    ]
                );
            }
        }

        $operator = Organization::query()
            ->where('type', Organization::TYPE_OPERATOR)
            ->where('status', true)
            ->whereNull('deleted_at')
            ->first();

        if ($operator) {
            $platformRoleName = (string) config('tenancy.roles.platform_admin');

            Role::updateOrCreate(
                ['organization_id' => $operator->id, 'name' => $platformRoleName],
                [
                    'id' => $this->deterministicId($operator->id, $platformRoleName),
                    'organization_id' => $operator->id,
                    'name' => $platformRoleName,
                    'description' => 'Administración multiempresa de la consultora',
                    'status' => true,
                ]
            );
        }
    }

    private function deterministicId(string $organizationId, string $name): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $organizationId.':'.$name);
    }
}
