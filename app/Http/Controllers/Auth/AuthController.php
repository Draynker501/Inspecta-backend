<?php

namespace App\Http\Controllers\Auth;

use App\Authorization\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Checklist;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Inspection;
use App\Models\Norm;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiErrorResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Autenticar usuario y emitir token personal de Sanctum.
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        // La contraseña se valida antes que el estado: un usuario inactivo
        // con contraseña incorrecta recibe invalid_credentials, no
        // user_inactive, para no revelar el estado sin conocer el secreto.
        if (! Auth::attempt($credentials)) {
            return ApiErrorResponse::make(ApiErrorCode::InvalidCredentials);
        }

        $user = Auth::user();

        // Usuario inactivo no puede autenticarse.
        if (! $user->status) {
            Auth::logout();

            return ApiErrorResponse::make(ApiErrorCode::UserInactive);
        }

        // Crear token personal de Sanctum (bearer token para API).
        $token = $user->createToken('inspecta-api')->plainTextToken;

        return response()->json([
            'message' => 'Autenticación exitosa.',
            'data' => [
                'user' => $user->only([
                    'id',
                    'name',
                    'email',
                    'organization_id',
                ]),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Obtener la identidad del usuario autenticado.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'message' => 'Usuario autenticado.',
            'data' => $user->only([
                'id',
                'name',
                'email',
                'organization_id',
            ]),
        ]);
    }

    /**
     * Capacidades generales del usuario en la organización activa.
     *
     * Las capacidades permiten determinar los módulos y operaciones
     * disponibles para el usuario dentro del tenant seleccionado.
     *
     * No sustituyen las comprobaciones de autorización sobre recursos
     * concretos, las cuales siguen siendo responsabilidad de las policies.
     *
     * Los nombres de los roles son exclusivamente informativos.
     */
    public function capabilities(Request $request)
    {
        $organizationId = app(TenantContext::class)->requireId();

        $user = $request->user();

        $gate = Gate::forUser($user);

        // Capacidades específicas de acciones correctivas.
        $correctiveActions = [
            'view' => $gate->allows(
                'viewAny',
                CorrectiveAction::class
            ),
            'create' => $gate->allows(
                'create',
                CorrectiveAction::class
            ),
            'update' => $gate->allows(
                'updateAny',
                CorrectiveAction::class
            ),
            'record_update' => $gate->allows(
                'recordUpdateAny',
                CorrectiveAction::class
            ),
            'verify' => $gate->allows(
                'verifyAny',
                CorrectiveAction::class
            ),
        ];

        // Capacidades específicas de administración.
        $administration = [
            'manage_users' => $user->hasPermission(
                Permission::UsersManage
            ),

            'manage_memberships' => $user->hasPermission(
                Permission::MembershipsManage
            ),

            'manage_roles' => $user->hasPermission(
                Permission::RolesManage
            ),

            'view_locations' => $user->hasPermission(
                Permission::PlantsView
            ),

            'manage_locations' => $user->hasPermission(
                Permission::PlantsManage
            ),

            'view_all_locations' => $user->hasPermission(
                Permission::LocationsViewAll
            ),
        ];

        // Operaciones que requieren múltiples permisos.
        $administration['create_users'] =
            $administration['manage_users']
            && $administration['manage_memberships'];

        $administration['assign_roles'] =
            $administration['manage_users']
            && $administration['manage_roles'];

        $administration['assign_locations'] =
            $administration['manage_users']
            && $administration['manage_locations']
            && $administration['view_locations'];

        // Capacidades generales de navegación por módulo.
        $modules = [
            'dashboard' => true,

            'inspections' => $gate->allows(
                'viewAny',
                Inspection::class
            ),

            'incidents' => $gate->allows(
                'viewAny',
                Incident::class
            ),

            'corrective_actions' => $correctiveActions['view'],

            'checklists' => $gate->allows(
                'viewAny',
                Checklist::class
            ),

            'norms' => $gate->allows(
                'viewAny',
                Norm::class
            ),

            'reports' => (
                $user->hasPermission(Permission::ReportsView)
                || $user->hasPermission(Permission::ReportsViewOwn)
            ),

            'administration' => (
                $administration['manage_users']
                || $administration['manage_roles']
                || $administration['manage_memberships']
                || $administration['manage_locations']
            ),
        ];

        // Roles activos del usuario dentro del tenant seleccionado.
        $roles = $user->roles()
            ->wherePivot('organization_id', $organizationId)
            ->where('roles.organization_id', $organizationId)
            ->where('roles.status', true)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->values()
            ->all();

        return response()->json([
            'organization_id' => $organizationId,
            'roles' => $roles,
            'modules' => $modules,
            'corrective_actions' => $correctiveActions,
            'administration' => $administration,
        ]);
    }

    /**
     * Cerrar sesión revocando el token actual.
     */
    public function logout(Request $request)
    {
        $token = $request->bearerToken();

        $accessToken = PersonalAccessToken::findToken($token);

        if ($accessToken) {
            $accessToken->delete();
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }
}
