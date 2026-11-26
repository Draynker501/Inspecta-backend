<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Services\ProfileMembershipService;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly ProfileMembershipService $membershipService
    ) {}

    /**
     * Actualizar la información personal del usuario autenticado.
     */
    public function update(
        UpdateProfileRequest $request
    ): JsonResponse {
        $user = $this->profileService->updateProfile(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'message' => 'Perfil actualizado correctamente.',
            'data' => $user->only([
                'id',
                'name',
                'email',
                'organization_id',
            ]),
        ]);
    }

    /**
     * Cambiar la contraseña del usuario autenticado.
     */
    public function updatePassword(
        UpdatePasswordRequest $request
    ): JsonResponse {
        $bearerToken = $request->bearerToken();

        abort_unless(
            is_string($bearerToken)
            && $bearerToken !== '',
            401
        );

        $this->profileService->updatePassword(
            $request->user(),
            $request->validated(),
            $bearerToken
        );

        return response()->json([
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }

    /**
     * Obtener la información organizacional propia.
     */
    public function membership(
        Request $request
    ): JsonResponse {
        return response()->json([
            'data' => $this->membershipService->show(
                $request->user()
            ),
        ]);
    }
}
