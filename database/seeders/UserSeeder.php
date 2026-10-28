<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserArea;
use App\Models\UserPlant;
use App\Models\UserRole;
use App\Support\Tenancy\MembershipBackfill;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organization = Organization::where('code', 'INSP-01')->first();

        if (!$organization) {
            return;
        }

        $adminRole = Role::where('name', 'Administrador')->first();
        $inspectorRole = Role::where('name', 'Inspector')->first();
        $supervisorRole = Role::where('name', 'Supervisor')->first();

        $plantNorte = \App\Models\Plant::where('code', 'PLN-01')->first();
        $areaSoldadura = \App\Models\Area::where('code', 'AR-SOLD-01')->first();

        $users = [
            [
                'id' => '018f2b3c-4d5e-7f8a-8000-112233445590',
                'name' => 'Administrador Sistema',
                'email' => 'admin@inspecta-demo.com',
                'password' => Hash::make('Admin123!'),
                'organization_id' => $organization->id,
                'email_verified_at' => now(),
                'status' => true,
                'role_id' => $adminRole?->id,
                'plant_ids' => $plantNorte ? [$plantNorte->id] : [],
                'area_ids' => $areaSoldadura ? [$areaSoldadura->id] : [],
            ],
            [
                'id' => '018f2b3c-4d5e-7f8a-8000-112233445591',
                'name' => 'Inspector Demo',
                'email' => 'inspector@inspecta-demo.com',
                'password' => Hash::make('Inspector123!'),
                'organization_id' => $organization->id,
                'email_verified_at' => now(),
                'status' => true,
                'role_id' => $inspectorRole?->id,
                'plant_ids' => $plantNorte ? [$plantNorte->id] : [],
                'area_ids' => $areaSoldadura ? [$areaSoldadura->id] : [],
            ],
            [
                'id' => '018f2b3c-4d5e-7f8a-8000-112233445592',
                'name' => 'Supervisor Demo',
                'email' => 'supervisor@inspecta-demo.com',
                'password' => Hash::make('Supervisor123!'),
                'organization_id' => $organization->id,
                'email_verified_at' => now(),
                'status' => true,
                'role_id' => $supervisorRole?->id,
                'plant_ids' => $plantNorte ? [$plantNorte->id] : [],
                'area_ids' => $areaSoldadura ? [$areaSoldadura->id] : [],
            ],
        ];

        foreach ($users as $userData) {
            $user = User::firstOrNew(['id' => $userData['id']]);

            $user->fill([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'organization_id' => $userData['organization_id'],
                'email_verified_at' => $userData['email_verified_at'],
                'status' => $userData['status'],
            ]);

            // Solo se define la contraseña al crear: re-sembrar no debe
            // regenerar el hash ni sobrescribir una contraseña ya cambiada.
            if (!$user->exists) {
                $user->password = $userData['password'];
            }

            $user->save();

            // Asignar rol y accesos con IDs de pivote estables entre siembras.
            if ($userData['role_id']) {
                $this->assignPivot(UserRole::class, [
                    'user_id' => $user->id,
                    'role_id' => $userData['role_id'],
                ], $user->organization_id);
            }

            foreach ($userData['plant_ids'] as $plantId) {
                $this->assignPivot(UserPlant::class, [
                    'user_id' => $user->id,
                    'plant_id' => $plantId,
                ], $user->organization_id);
            }

            foreach ($userData['area_ids'] as $areaId) {
                $this->assignPivot(UserArea::class, [
                    'user_id' => $user->id,
                    'area_id' => $areaId,
                ], $user->organization_id);
            }
        }

        // Doble escritura para usuarios ya existentes: asegurar una
        // membresía activa por cada (user_id, users.organization_id).
        // Idempotente: no duplica ni modifica membresías existentes.
        MembershipBackfill::run();
    }

    /**
     * Crea la asignación solo si no existe y conserva su id entre siembras.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  array<string, string>  $keys
     */
    private function assignPivot(string $modelClass, array $keys, string $organizationId): void
    {
        $pivot = $modelClass::firstOrCreate($keys, [
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
        ]);

        if ($pivot->organization_id !== $organizationId) {
            $pivot->update(['organization_id' => $organizationId]);
        }
    }
}
