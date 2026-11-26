<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\TenantController;
use App\Http\Requests\Administration\IndexUsersRequest;
use App\Http\Requests\Administration\ReplaceUserLocationsRequest;
use App\Http\Requests\Administration\ReplaceUserRolesRequest;
use App\Http\Requests\Administration\StoreUserRequest;
use App\Http\Requests\Administration\UpdateMembershipStatusRequest;
use App\Services\AdministrationUserService;
use App\Support\Locations\LocationReadScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends TenantController
{
    public function index(
        IndexUsersRequest $request,
        AdministrationUserService $service
    ): JsonResponse {
        return response()->json(
            $service->index(
                $request->user(),
                $request->validated()
            )
        );
    }

    public function store(
        StoreUserRequest $request,
        AdministrationUserService $service
    ): JsonResponse {
        $user = $service->create(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data' => $user,
        ], 201);
    }

    public function show(
        Request $request,
        string $userId,
        AdministrationUserService $service
    ): JsonResponse {
        LocationReadScope::rejectUnknownQuery(
            $request,
            []
        );

        $user = $service->show(
            $request->user(),
            $userId
        );

        return response()->json([
            'data' => $user,
        ]);
    }

    public function updateMembershipStatus(
        UpdateMembershipStatusRequest $request,
        string $userId,
        AdministrationUserService $service
    ): JsonResponse {
        $validated = $request->validated();

        $user = $service->updateMembershipStatus(
            $request->user(),
            $userId,
            $validated['status']
        );

        return response()->json([
            'data' => $user,
        ]);
    }

    public function replaceRoles(
        ReplaceUserRolesRequest $request,
        string $userId,
        AdministrationUserService $service
    ): JsonResponse {
        return response()->json([
            'data' => $service->replaceRoles(
                $request->user(),
                $userId,
                $request->validated('role_ids')
            ),
        ]);
    }

    public function replaceLocations(
        ReplaceUserLocationsRequest $request,
        string $userId,
        AdministrationUserService $service
    ): JsonResponse {
        $validated = $request->validated();

        return response()->json([
            'data' => $service->replaceLocations(
                $request->user(),
                $userId,
                $validated['plant_ids'],
                $validated['area_ids']
            ),
        ]);
    }
}
