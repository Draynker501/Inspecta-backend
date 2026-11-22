<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\CorrectiveAction;
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

        // Usuario inactivo no puede autenticarse
        if (! $user->status) {
            Auth::logout();

            return ApiErrorResponse::make(ApiErrorCode::UserInactive);
        }

        // Crear token personal de Sanctum (bearer token para API)
        $token = $user->createToken('inspecta-api')->plainTextToken;

        return response()->json([
            'message' => 'Autenticación exitosa.',
            'data' => [
                'user' => $user->only(['id', 'name', 'email', 'organization_id']),
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
            'data' => $user->only(['id', 'name', 'email', 'organization_id']),
        ]);
    }

    /**
     * Capacidades generales del usuario en la organización activa.
     *
     * Son capacidades de sección/operación, no autorizaciones sobre un
     * recurso concreto: viewAny/create/updateAny/recordUpdateAny siguen
     * delegando en CorrectiveActionPolicy y cada recurso se autoriza después
     * con sus propias comprobaciones de organización y ubicación.
     */
    public function capabilities(Request $request)
    {
        $organizationId = app(TenantContext::class)->requireId();
        $gate = Gate::forUser($request->user());

        return response()->json([
            'organization_id' => $organizationId,
            'corrective_actions' => [
                'view' => $gate->allows('viewAny', CorrectiveAction::class),
                'create' => $gate->allows('create', CorrectiveAction::class),
                'update' => $gate->allows('updateAny', CorrectiveAction::class),
                'record_update' => $gate->allows('recordUpdateAny', CorrectiveAction::class),
                'verify' => $gate->allows('verifyAny', CorrectiveAction::class),
            ],
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
