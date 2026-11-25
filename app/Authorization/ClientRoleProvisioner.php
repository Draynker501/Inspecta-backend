<?php

namespace App\Authorization;

use App\Models\Organization;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea las plantillas de rol de una organizacion cliente.
 *
 * Algoritmo unico compartido por RoleSeeder y por el bootstrap de clientes:
 * - busca primero por el ID determinista y, como compatibilidad historica,
 *   por nombre;
 * - solo asigna `system_key` a la plantilla con ID determinista;
 * - solo concede permisos cuando el rol se crea, nunca sobre uno existente;
 * - no sobrescribe roles personalizados que reutilicen el nombre.
 */
final class ClientRoleProvisioner
{
    /**
     * @param  list<array{name: string, description: string, system_key: string, permissions: list<Permission>}>|null  $templates
     * @return array<string, Role>
     */
    public function provision(Organization $organization, ?array $templates = null): array
    {
        $templates ??= ClientRoleTemplate::roles();
        $roles = [];

        foreach ($templates as $template) {
            $deterministicId = ClientRoleTemplate::deterministicId($organization->id, $template['name']);

            $role = Role::query()
                ->where('organization_id', $organization->id)
                ->where('id', $deterministicId)
                ->first();

            if (! $role) {
                $role = Role::query()
                    ->where('organization_id', $organization->id)
                    ->where('name', $template['name'])
                    ->first();
            }

            $wasCreated = false;

            if (! $role) {
                $role = Role::create([
                    'id' => $deterministicId,
                    'organization_id' => $organization->id,
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'status' => true,
                ]);
                $wasCreated = true;
            }

            if ($role->system_key === null && $role->id === $deterministicId) {
                DB::table('roles')
                    ->where('id', $role->id)
                    ->whereNull('system_key')
                    ->update(['system_key' => $template['system_key']]);
            }

            if ($wasCreated) {
                foreach ($template['permissions'] as $permission) {
                    RolePermission::create([
                        'id' => (string) Str::uuid7(),
                        'organization_id' => $organization->id,
                        'role_id' => $role->id,
                        'permission' => $permission->value,
                    ]);
                }
            }

            $roles[$template['name']] = $role->refresh();
        }

        return $roles;
    }
}
