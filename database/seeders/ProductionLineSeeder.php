<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\ProductionLine;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductionLineSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $areas = Area::all();

        if ($areas->isEmpty()) {
            return;
        }

        $areaSoldadura = $areas->firstWhere('code', 'AR-SOLD-01');
        $areaEnsamblaje = $areas->firstWhere('code', 'AR-ENS-01');

        $lines = [];

        if ($areaSoldadura) {
            $lines[] = [
                'id' => '018f1a31-6666-7e5f-8000-abcdef123456',
                'organization_id' => $areaSoldadura->organization_id,
                'area_id' => $areaSoldadura->id,
                'name' => 'Línea Automotriz A',
                'code' => 'LN-AUT-A',
                'description' => 'Ensamblaje de chasis',
                'status' => true,
            ];
        }

        if ($areaEnsamblaje) {
            $lines[] = [
                'id' => '018f1a32-7777-7e5f-8000-fedcba654321',
                'organization_id' => $areaEnsamblaje->organization_id,
                'area_id' => $areaEnsamblaje->id,
                'name' => 'Línea Electrónica B',
                'code' => 'LN-ELE-B',
                'description' => 'Ensamblaje de tarjetas electrónicas',
                'status' => true,
            ];
        }

        foreach ($lines as $line) {
            ProductionLine::updateOrCreate(
                ['id' => $line['id']],
                $line
            );
        }
    }
}
