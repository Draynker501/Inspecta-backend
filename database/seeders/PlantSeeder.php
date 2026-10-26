<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Plant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PlantSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organization = Organization::first();

        if (!$organization) {
            return;
        }

        $plants = [
            [
                'id' => '018f1a2c-1111-7e5f-8000-abcdef123456',
                'organization_id' => $organization->id,
                'name' => 'Planta Norte',
                'code' => 'PLN-01',
                'address' => 'Av. Industria 100, Zona Industrial',
                'description' => 'Planta principal de manufactura',
                'status' => true,
            ],
            [
                'id' => '018f1a2d-2222-7e5f-8000-fedcba654321',
                'organization_id' => $organization->id,
                'name' => 'Planta Sur',
                'code' => 'PLN-02',
                'address' => 'Carretera a Guadalajara Km 45',
                'description' => 'Planta secundaria de ensamblaje',
                'status' => true,
            ],
        ];

        foreach ($plants as $plant) {
            Plant::updateOrCreate(
                ['id' => $plant['id']],
                $plant
            );
        }
    }
}
