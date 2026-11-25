<?php

namespace Database\Seeders;

use App\Authorization\ClientRoleProvisioner;
use App\Authorization\ClientRoleTemplate;
use App\Models\Organization;
use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $context = app(TenantContext::class);
        if ($context->id() !== null) {
            throw new LogicException('RoleSeeder requiere ejecutarse sin contexto organizacional.');
        }

        $clientRoles = ClientRoleTemplate::roles();

        try {
            DB::transaction(function () use ($clientRoles): void {
                $this->seedRoles($clientRoles);
            });
        } finally {
            // WithoutModelEvents suprime la invalidación habitual de RolePermission.
            $context->flushPermissionCache();
        }
    }

    private function seedRoles(array $clientRoles): void
    {
        $clients = Organization::query()
            ->where('type', Organization::TYPE_CLIENT)
            ->where('status', true)
            ->whereNull('deleted_at')
            ->get();

        $provisioner = app(ClientRoleProvisioner::class);

        foreach ($clients as $organization) {
            $provisioner->provision($organization, $clientRoles);
        }

        $operator = Organization::query()
            ->where('type', Organization::TYPE_OPERATOR)
            ->where('status', true)
            ->whereNull('deleted_at')
            ->first();

        if ($operator) {
            $platformRoleName = (string) config('tenancy.roles.platform_admin');

            Role::updateOrCreate(
                ['organization_id' => $operator->id, 'name' => $platformRoleName],
                [
                    'id' => ClientRoleTemplate::deterministicId($operator->id, $platformRoleName),
                    'organization_id' => $operator->id,
                    'name' => $platformRoleName,
                    'description' => 'Administración multiempresa de la consultora',
                    'status' => true,
                ]
            );
        }
    }
}
