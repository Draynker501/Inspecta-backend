<?php

namespace Database\Seeders;

use App\Authorization\Permission;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RolePermission;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Ramsey\Uuid\Uuid;

class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $context = app(TenantContext::class);
        if ($context->id() !== null) {
            throw new LogicException('RoleSeeder requiere ejecutarse sin contexto organizacional.');
        }

        $clientRoles = [
            ['name' => 'Administrador', 'description' => 'Acceso completo a todas las funciones del sistema'],
            ['name' => 'Inspector', 'description' => 'Puede realizar inspecciones y reportar incidencias'],
            ['name' => 'Supervisor', 'description' => 'Puede confirmar o rechazar inspecciones'],
        ];

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

        foreach ($clients as $organization) {
            foreach ($clientRoles as $role) {
                $systemKey = match ($role['name']) {
                    'Administrador' => 'admin',
                    'Supervisor' => 'supervisor',
                    'Inspector' => 'inspector',
                    default => null,
                };
                $deterministicId = $this->deterministicId(
                    $organization->id,
                    $role['name']
                );

                // Identificar la plantilla por su ID técnico original. Solo si
                // no existe se acepta el nombre como compatibilidad histórica,
                // sin apropiarse de un rol personalizado con ese nombre.
                $createdRole = Role::query()
                    ->where('organization_id', $organization->id)
                    ->where('id', $deterministicId)
                    ->first();

                if (! $createdRole) {
                    $createdRole = Role::query()
                        ->where('organization_id', $organization->id)
                        ->where('name', $role['name'])
                        ->first();
                }

                $wasCreated = false;
                if (! $createdRole) {
                    $createdRole = Role::create([
                        'id' => $deterministicId,
                        'organization_id' => $organization->id,
                        'name' => $role['name'],
                        'description' => $role['description'],
                        'status' => true,
                    ]);
                    $wasCreated = true;
                }

                // Solo la plantilla con ID determinista recibe system_key.
                // Un rol personalizado que reutilice el nombre no se toca.
                if ($systemKey !== null
                    && $createdRole->system_key === null
                    && $createdRole->id === $deterministicId) {
                    DB::table('roles')
                        ->where('id', $createdRole->id)
                        ->whereNull('system_key')
                        ->update(['system_key' => $systemKey]);
                }

                // Solo una creación en esta transacción identifica sin ambigüedad
                // una plantilla inicial; jamás rellenar permisos de roles existentes.
                if ($wasCreated) {
                    $permissions = match ($role['name']) {
                        'Administrador' => [Permission::ChecklistsView, Permission::ChecklistsManage, Permission::InspectionsExecute, Permission::InspectionsManage, Permission::PlantsView],
                        'Supervisor' => [Permission::ChecklistsView, Permission::InspectionsManage, Permission::PlantsView],
                        'Inspector' => [Permission::ChecklistsView, Permission::InspectionsExecute, Permission::PlantsView],
                        default => [],
                    };

                    foreach ($permissions as $permission) {
                        RolePermission::create([
                            'id' => (string) Str::uuid7(),
                            'organization_id' => $organization->id,
                            'role_id' => $createdRole->id,
                            'permission' => $permission->value,
                        ]);
                    }
                }
            }
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
                    'id' => $this->deterministicId($operator->id, $platformRoleName),
                    'organization_id' => $operator->id,
                    'name' => $platformRoleName,
                    'description' => 'Administración multiempresa de la consultora',
                    'status' => true,
                ]
            );
        }
    }

    private function deterministicId(string $organizationId, string $name): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $organizationId.':'.$name);
    }
}
