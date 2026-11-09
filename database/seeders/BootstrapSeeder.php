<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

class BootstrapSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $operator = Organization::updateOrCreate(
            ['code' => 'INSPECTA-OP'],
            [
                'id' => '018f1a2b-3c4d-7e5f-8000-9a8b7c6d5e51',
                'code' => 'INSPECTA-OP',
                'name' => 'Consultora INSPECTA',
                'type' => Organization::TYPE_OPERATOR,
                'tax_id' => 'OPR000000000',
                'address' => 'Oficina central INSPECTA',
                'description' => 'Organización operadora de la consultora',
                'phone' => '+52 33 0000 0000',
                'email' => 'operaciones@inspecta.com',
                'status' => true,
            ]
        );

        $platformRoleName = (string) config('tenancy.roles.platform_admin');

        if ($platformRoleName === '') {
            return;
        }

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

    private function deterministicId(string $organizationId, string $name): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $organizationId.':'.$name);
    }
}
