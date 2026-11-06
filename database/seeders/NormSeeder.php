<?php

namespace Database\Seeders;

use App\Models\Norm;
use App\Models\Organization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

class NormSeeder extends Seeder
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
            Norm::updateOrCreate(
                ['organization_id' => $organization->id, 'code' => 'NOM-017-STPS'],
                [
                    'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $organization->id.':'.'NOM-017-STPS'),
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
}
