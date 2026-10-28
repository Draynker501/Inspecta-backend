<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OrganizationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Organization::updateOrCreate(
            ['code' => 'INSP-01'],
            [
                'id' => '018f1a2b-3c4d-7e5f-8000-9a8b7c6d5e4f',
                'name' => 'Inspecta Demo Organization',
                'type' => Organization::TYPE_CLIENT,
                'tax_id' => 'ABC123456789',
                'address' => 'Av. Industria 100, Jalisco, México',
                'description' => 'Organización de demostración para entorno de desarrollo',
                'phone' => '+52 33 1234 5678',
                'email' => 'contacto@inspecta-demo.com',
                'status' => true,
            ]
        );
    }
}
