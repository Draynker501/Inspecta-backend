<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Plant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AreaSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $plants = Plant::all();

        if ($plants->isEmpty()) {
            return;
        }

        $plantNorte = $plants->firstWhere('code', 'PLN-01');
        $plantSur = $plants->firstWhere('code', 'PLN-02');

        $areas = [];

        if ($plantNorte) {
            $areas[] = [
                'id' => '018f1a2e-3333-7e5f-8000-abcdef123456',
                'organization_id' => $plantNorte->organization_id,
                'plant_id' => $plantNorte->id,
                'name' => 'Área de Soldadura',
                'code' => 'AR-SOLD-01',
                'type' => 'production',
                'description' => 'Zona de soldadura robótica',
                'status' => true,
            ];
            $areas[] = [
                'id' => '018f1a2f-4444-7e5f-8000-abcdef123456',
                'organization_id' => $plantNorte->organization_id,
                'plant_id' => $plantNorte->id,
                'name' => 'Área de Almacén',
                'code' => 'AR-ALM-01',
                'type' => 'warehouse',
                'description' => 'Zona de almacenamiento de materias primas',
                'status' => true,
            ];
        }

        if ($plantSur) {
            $areas[] = [
                'id' => '018f1a30-5555-7e5f-8000-fedcba654321',
                'organization_id' => $plantSur->organization_id,
                'plant_id' => $plantSur->id,
                'name' => 'Área de Ensamblaje',
                'code' => 'AR-ENS-01',
                'type' => 'production',
                'description' => 'Zona de ensamblaje de productos terminados',
                'status' => true,
            ];
        }

        foreach ($areas as $area) {
            Area::updateOrCreate(
                ['id' => $area['id']],
                $area
            );
        }
    }
}
