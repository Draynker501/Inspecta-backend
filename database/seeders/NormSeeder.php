<?php

namespace Database\Seeders;

use App\Models\Norm;
use App\Models\Organization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class NormSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organization = Organization::first();

        if (!$organization) {
            return;
        }

        Norm::updateOrCreate(
            ['id' => '018f5e6a-9999-7a8b-8000-abcdef123456'],
            [
                'organization_id' => $organization->id,
                'code' => 'NOM-017-STPS',
                'name' => 'Equipo de Protección Personal',
                'description' => 'Selección, uso y manejo en los centros de trabajo.',
                'issuing_body' => 'Secretaría del Trabajo y Previsión Social (STPS)',
                'version' => '2008',
                'effective_date' => '2008-12-09',
                'status' => true,
            ]
        );
    }
}
