<?php

namespace App\Authorization;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Plantillas oficiales de roles para una organizacion cliente.
 *
 * Fuente unica compartida por RoleSeeder y por el bootstrap de clientes, de
 * modo que una organizacion nueva reciba exactamente los mismos roles y
 * permisos que las creadas por el seeder, sin duplicar el catalogo.
 */
final class ClientRoleTemplate
{
    public const ADMIN = 'Administrador';

    /**
     * @return list<array{name: string, description: string, system_key: string, permissions: list<Permission>}>
     */
    public static function roles(): array
    {
        return [
            [
                'name' => self::ADMIN,
                'description' => 'Acceso completo a todas las funciones del sistema',
                'system_key' => 'admin',
                'permissions' => [
                    Permission::ChecklistsView,
                    Permission::ChecklistsManage,
                    Permission::InspectionsExecute,
                    Permission::InspectionsManage,
                    Permission::PlantsView,
                ],
            ],
            [
                'name' => 'Inspector',
                'description' => 'Puede realizar inspecciones y reportar incidencias',
                'system_key' => 'inspector',
                'permissions' => [
                    Permission::ChecklistsView,
                    Permission::InspectionsExecute,
                    Permission::PlantsView,
                ],
            ],
            [
                'name' => 'Supervisor',
                'description' => 'Puede confirmar o rechazar inspecciones',
                'system_key' => 'supervisor',
                'permissions' => [
                    Permission::ChecklistsView,
                    Permission::InspectionsManage,
                    Permission::PlantsView,
                ],
            ],
        ];
    }

    /**
     * @return array{name: string, description: string, system_key: string, permissions: list<Permission>}
     */
    public static function byName(string $name): array
    {
        foreach (self::roles() as $role) {
            if ($role['name'] === $name) {
                return $role;
            }
        }

        throw new InvalidArgumentException("Rol de cliente desconocido: {$name}.");
    }

    /**
     * Identificador tecnico estable de la plantilla dentro de la organizacion.
     */
    public static function deterministicId(string $organizationId, string $name): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $organizationId.':'.$name);
    }
}
