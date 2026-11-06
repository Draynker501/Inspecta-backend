<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\Organization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

class ChecklistSeeder extends Seeder
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
            Checklist::updateOrCreate(
                ['organization_id' => $organization->id, 'name' => 'Inspección de EHS Semanal'],
                [
                    'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $organization->id.':'.'Inspección de EHS Semanal'),
                    'organization_id' => $organization->id,
                    'name' => 'Inspección de EHS Semanal',
                    'description' => 'Revisión rutinaria de equipo de protección personal y extintores.',
                    'status' => true,
                ]
            );
        }
    }
}
