<?php

namespace App\Authorization;

/**
 * Catálogo de permisos de INSPECTA (D-40).
 *
 * Los permisos viven en código (`recurso.accion`); la base de datos guarda
 * solo `role_permissions` (rol, organización, permiso) con FK compuesta al rol.
 * No hay tabla `permissions`.
 *
 * Invariantes (validadas por tests):
 * - cada case tiene un valor string único;
 * - el valor sigue el formato `recurso.accion` (minúsculas y guion bajo).
 */
enum Permission: string
{
    case PlantsView = 'plants.view';
    case PlantsManage = 'plants.manage';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case MembershipsManage = 'memberships.manage';
    case NormsView = 'norms.view';
    case NormsManage = 'norms.manage';
    case InspectionsManage = 'inspections.manage';
    case InspectionsExecute = 'inspections.execute';
    case FindingsRegister = 'findings.register';
    case FindingsClose = 'findings.close';
    case CorrectiveActionsManage = 'corrective_actions.manage';
    case ReportsView = 'reports.view';
    case ReportsViewOwn = 'reports.view_own';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
