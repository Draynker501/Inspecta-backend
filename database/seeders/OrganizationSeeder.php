<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organizations = [
            [
                'id' => '018f1a2b-3c4d-7e5f-8000-9a8b7c6d5e4f',
                'code' => 'INSP-01',
                'name' => 'Inspecta Demo Organization',
                'type' => Organization::TYPE_CLIENT,
                'tax_id' => 'ABC123456789',
                'address' => 'Av. Industria 100, Jalisco, México',
                'description' => 'Organización de demostración para entorno de desarrollo',
                'phone' => '+52 33 1234 5678',
                'email' => 'contacto@inspecta-demo.com',
                'status' => true,
            ],
            [
                'id' => '018f1a2b-3c4d-7e5f-8000-9a8b7c6d5e50',
                'code' => 'ORG-02',
                'name' => 'Organización Demo 2',
                'type' => Organization::TYPE_CLIENT,
                'tax_id' => 'DEF987654321',
                'address' => 'Calle Secundaria 200, CDMX, México',
                'description' => 'Segunda organización cliente para entorno de desarrollo',
                'phone' => '+52 55 9876 5432',
                'email' => 'contacto@org02-demo.com',
                'status' => true,
            ],
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
            ],
        ];

        foreach ($organizations as $data) {
            Organization::updateOrCreate(
                ['code' => $data['code']],
                $data
            );
        }
    }
}
