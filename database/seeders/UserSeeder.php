<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plant;
use App\Models\Role;
use App\Models\User;
use App\Models\UserArea;
use App\Models\UserPlant;
use App\Models\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    public static function allowsFixedPasswordUsers(): bool
    {
        return DemoData::allowed();
    }

    public function run(): void
    {
        if (! static::allowsFixedPasswordUsers()) {
            fwrite(STDOUT, "WARNING: UserSeeder: usuarios con contraseñas fijas omitidos (entorno no local/testing).\n");

            return;
        }

        $organizations = Organization::query()
            ->whereIn('code', ['INSP-01', 'ORG-02', 'INSPECTA-OP'])
            ->get()
            ->keyBy('code');

        $insp01 = $organizations->get('INSP-01');
        $org02 = $organizations->get('ORG-02');
        $operator = $organizations->get('INSPECTA-OP');

        if (! $insp01) {
            return;
        }

        $plantNorte = Plant::where('organization_id', $insp01->id)->where('code', 'PLN-01')->first();
        $areaSoldadura = Area::where('organization_id', $insp01->id)->where('code', 'AR-SOLD-01')->first();

        $users = [
            [
                'id' => '018f2b3c-4d5e-7f8a-8000-112233445590',
                'name' => 'Administrador Sistema',
                'email' => 'admin@inspecta-demo.com',
                'password' => Hash::make('Admin123!'),
                'primary_org' => 'INSP-01',
                'memberships' => ['INSP-01'],
                'roles' => ['INSP-01' => 'Administrador'],
                'plant_ids' => $plantNorte ? [$plantNorte->id] : [],
                'area_ids' => $areaSoldadura ? [$areaSoldadura->id] : [],
                'plant_org' => 'INSP-01',
                'area_org' => 'INSP-01',
            ],
            [
                'id' => '018f2b3c-4d5e-7f8a-8000-112233445591',
                'name' => 'Inspector Demo',
                'email' => 'inspector@inspecta-demo.com',
                'password' => Hash::make('Inspector123!'),
                'primary_org' => 'INSP-01',
                'memberships' => ['INSP-01'],
                'roles' => ['INSP-01' => 'Inspector'],
                'plant_ids' => $plantNorte ? [$plantNorte->id] : [],
                'area_ids' => $areaSoldadura ? [$areaSoldadura->id] : [],
                'plant_org' => 'INSP-01',
                'area_org' => 'INSP-01',
            ],
            [
                'id' => '018f2b3c-4d5e-7f8a-8000-112233445592',
                'name' => 'Supervisor Demo',
                'email' => 'supervisor@inspecta-demo.com',
                'password' => Hash::make('Supervisor123!'),
                'primary_org' => 'INSP-01',
                'memberships' => ['INSP-01'],
                'roles' => ['INSP-01' => 'Supervisor'],
                'plant_ids' => $plantNorte ? [$plantNorte->id] : [],
                'area_ids' => $areaSoldadura ? [$areaSoldadura->id] : [],
                'plant_org' => 'INSP-01',
                'area_org' => 'INSP-01',
            ],
        ];

        if ($org02) {
            $users[] = [
                'id' => '018f2b3c-4d5e-7f8a-8000-112233445593',
                'name' => 'Administrador Org2',
                'email' => 'admin2@inspecta-demo.com',
                'password' => Hash::make('Admin123!'),
                'primary_org' => 'ORG-02',
                'memberships' => ['ORG-02'],
                'roles' => ['ORG-02' => 'Administrador'],
                'plant_ids' => [],
                'area_ids' => [],
                'plant_org' => null,
                'area_org' => null,
            ];
        }

        if ($operator) {
            $clientCodes = ['INSP-01'];
            if ($org02) {
                $clientCodes[] = 'ORG-02';
            }

            $users[] = [
                'id' => '018f2b3c-4d5e-7f8a-8000-112233445594',
                'name' => 'Consultor Plataforma',
                'email' => 'consultor@inspecta.com',
                'password' => Hash::make('Consultor123!'),
                'primary_org' => 'INSPECTA-OP',
                'memberships' => array_merge(['INSPECTA-OP'], $clientCodes),
                'roles' => ['INSPECTA-OP' => (string) config('tenancy.roles.platform_admin')],
                'plant_ids' => [],
                'area_ids' => [],
                'plant_org' => null,
                'area_org' => null,
            ];
        }

        // 1. Crear/actualizar usuarios.
        $records = [];
        foreach ($users as $data) {
            $primaryOrg = $organizations->get($data['primary_org']);
            $user = User::firstOrNew(['id' => $data['id']]);

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'organization_id' => $primaryOrg?->id,
                'email_verified_at' => now(),
                'status' => true,
            ]);

            if (! $user->exists) {
                $user->password = $data['password'];
            }

            $user->save();

            $records[] = ['user' => $user, 'data' => $data];
        }

        // 2. Membresías.
        foreach ($records as $record) {
            $user = $record['user'];
            $data = $record['data'];

            foreach ($data['memberships'] as $code) {
                $org = $organizations->get($code);
                if (! $org) {
                    continue;
                }

                OrganizationMembership::firstOrCreate(
                    ['user_id' => $user->id, 'organization_id' => $org->id],
                    [
                        'id' => (string) Str::uuid7(),
                        'status' => OrganizationMembership::STATUS_ACTIVE,
                    ],
                );
            }
        }

        // 3. Pivotes.
        foreach ($records as $record) {
            $user = $record['user'];
            $data = $record['data'];

            foreach ($data['roles'] as $orgCode => $roleName) {
                $org = $organizations->get($orgCode);
                $roleModel = $org ? Role::where('organization_id', $org->id)->where('name', $roleName)->first() : null;

                if ($org && $roleModel) {
                    $this->assignPivot(UserRole::class, [
                        'user_id' => $user->id,
                        'role_id' => $roleModel->id,
                    ], $org->id);
                }
            }

            if (! empty($data['plant_ids'])) {
                $org = $organizations->get($data['plant_org']);
                if ($org) {
                    foreach ($data['plant_ids'] as $plantId) {
                        $this->assignPivot(UserPlant::class, [
                            'user_id' => $user->id,
                            'plant_id' => $plantId,
                        ], $org->id);
                    }
                }
            }

            if (! empty($data['area_ids'])) {
                $org = $organizations->get($data['area_org']);
                if ($org) {
                    foreach ($data['area_ids'] as $areaId) {
                        $this->assignPivot(UserArea::class, [
                            'user_id' => $user->id,
                            'area_id' => $areaId,
                        ], $org->id);
                    }
                }
            }
        }
    }

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
