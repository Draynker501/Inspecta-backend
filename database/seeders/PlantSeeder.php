<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Plant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

class PlantSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $clients = Organization::query()
            ->where('type', Organization::TYPE_CLIENT)
            ->where('status', true)
            ->whereNull('deleted_at')
            ->get();

        foreach ($clients as $organization) {
            $plants = [
                [
                    'code' => 'PLN-01',
                    'name' => 'Planta Norte',
                    'address' => 'Av. Industria 100, Zona Industrial',
                    'description' => 'Planta principal de manufactura',
                ],
                [
                    'code' => 'PLN-02',
                    'name' => 'Planta Sur',
                    'address' => 'Carretera a Guadalajara Km 45',
                    'description' => 'Planta secundaria de ensamblaje',
                ],
            ];

            foreach ($plants as $plant) {
                Plant::updateOrCreate(
                    ['organization_id' => $organization->id, 'code' => $plant['code']],
                    [
                        'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $organization->id.':'.$plant['code']),
                        'organization_id' => $organization->id,
                        'name' => $plant['name'],
                        'code' => $plant['code'],
                        'address' => $plant['address'],
                        'description' => $plant['description'],
                        'status' => true,
                    ]
                );
            }
        }
    }
}
