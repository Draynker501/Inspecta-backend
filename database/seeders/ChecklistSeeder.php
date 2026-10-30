<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\Organization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ChecklistSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organization = Organization::first();

        if (!$organization) {
            return;
        }

        Checklist::updateOrCreate(
            ['id' => '018f3d4e-1234-7a8b-8000-abcdef123456'],
            [
                'organization_id' => $organization->id,
                'name' => 'Inspección de EHS Semanal',
                'description' => 'Revisión rutinaria de equipo de protección personal y extintores.',
                'status' => true,
            ]
        );
    }
}
