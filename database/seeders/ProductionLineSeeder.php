<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Organization;
use App\Models\ProductionLine;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

class ProductionLineSeeder extends Seeder
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
            $areaSoldadura = Area::where('organization_id', $organization->id)->where('code', 'AR-SOLD-01')->first();
            $areaEnsamblaje = Area::where('organization_id', $organization->id)->where('code', 'AR-ENS-01')->first();

            $lines = [];

            if ($areaSoldadura) {
                $lines[] = [
                    'code' => 'LN-AUT-A',
                    'area' => $areaSoldadura,
                    'name' => 'Línea Automotriz A',
                    'description' => 'Ensamblaje de chasis',
                ];
            }

            if ($areaEnsamblaje) {
                $lines[] = [
                    'code' => 'LN-ELE-B',
                    'area' => $areaEnsamblaje,
                    'name' => 'Línea Electrónica B',
                    'description' => 'Ensamblaje de tarjetas electrónicas',
                ];
            }

            foreach ($lines as $line) {
                $area = $line['area'];

                ProductionLine::updateOrCreate(
                    ['organization_id' => $organization->id, 'code' => $line['code']],
                    [
                        'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $organization->id.':'.$line['code']),
                        'organization_id' => $organization->id,
                        'area_id' => $area->id,
                        'name' => $line['name'],
                        'code' => $line['code'],
                        'description' => $line['description'],
                        'status' => true,
                    ]
                );
            }
        }
    }
}
