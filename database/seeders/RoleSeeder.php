<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organization = Organization::first();

        if (!$organization) {
            return;
        }

        $roles = [
            [
                'id' => '018f2b3c-4d5e-7f8a-8000-112233445566',
                'name' => 'Administrador',
                'description' => 'Acceso completo a todas las funciones del sistema',
                'organization_id' => $organization->id,
                'status' => true,
            ],
            [
                'id' => '018f2b3c-4d5e-7f8a-8000-112233445567',
                'name' => 'Inspector',
                'description' => 'Puede realizar inspecciones y reportar incidencias',
                'organization_id' => $organization->id,
                'status' => true,
            ],
            [
                'id' => '018f2b3c-4d5e-7f8a-8000-112233445568',
                'name' => 'Supervisor',
                'description' => 'Puede confirmar o rechazar inspecciones',
                'organization_id' => $organization->id,
                'status' => true,
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['id' => $role['id']],
                $role
            );
        }
    }
}
