<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Organization;
use App\Models\Plant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

class AreaSeeder extends Seeder
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
            $plantNorte = Plant::where('organization_id', $organization->id)->where('code', 'PLN-01')->first();
            $plantSur = Plant::where('organization_id', $organization->id)->where('code', 'PLN-02')->first();

            $areas = [];

            if ($plantNorte) {
                $areas[] = [
                    'code' => 'AR-SOLD-01',
                    'plant' => $plantNorte,
                    'name' => 'Área de Soldadura',
                    'type' => 'production',
                    'description' => 'Zona de soldadura robótica',
                ];
                $areas[] = [
                    'code' => 'AR-ALM-01',
                    'plant' => $plantNorte,
                    'name' => 'Área de Almacén',
                    'type' => 'warehouse',
                    'description' => 'Zona de almacenamiento de materias primas',
                ];
            }

            if ($plantSur) {
                $areas[] = [
                    'code' => 'AR-ENS-01',
                    'plant' => $plantSur,
                    'name' => 'Área de Ensamblaje',
                    'type' => 'production',
                    'description' => 'Zona de ensamblaje de productos terminados',
                ];
            }

            foreach ($areas as $area) {
                $plant = $area['plant'];

                Area::updateOrCreate(
                    ['organization_id' => $organization->id, 'code' => $area['code']],
                    [
                        'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $organization->id.':'.$area['code']),
                        'organization_id' => $organization->id,
                        'plant_id' => $plant->id,
                        'name' => $area['name'],
                        'code' => $area['code'],
                        'type' => $area['type'],
                        'description' => $area['description'],
                        'status' => true,
                    ]
                );
            }
        }
    }
}
