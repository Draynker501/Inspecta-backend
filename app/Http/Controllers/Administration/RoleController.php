<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\TenantController;
use App\Http\Requests\Administration\RoleRequest;
use App\Services\AdministrationRoleService;
use Illuminate\Http\JsonResponse;

class RoleController extends TenantController
{
    public function index(RoleRequest $request, AdministrationRoleService $service): JsonResponse
    {
        return response()->json($service->index($request->user(), $request->validated()));
    }

    public function store(RoleRequest $request, AdministrationRoleService $service): JsonResponse
    {
        return response()->json(['data' => $service->create($request->user(), $request->validated())], 201);
    }

    public function show(RoleRequest $request, string $roleId, AdministrationRoleService $service): JsonResponse
    {
        return response()->json(['data' => $service->show($request->user(), $roleId)]);
    }

    public function update(RoleRequest $request, string $roleId, AdministrationRoleService $service): JsonResponse
    {
        return response()->json(['data' => $service->update($request->user(), $roleId, $request->validated())]);
    }

    public function updateStatus(RoleRequest $request, string $roleId, AdministrationRoleService $service): JsonResponse
    {
        return response()->json(['data' => $service->updateStatus($request->user(), $roleId, $request->validated('status'))]);
    }

    public function replacePermissions(RoleRequest $request, string $roleId, AdministrationRoleService $service): JsonResponse
    {
        return response()->json(['data' => $service->replacePermissions($request->user(), $roleId, $request->validated('permissions'))]);
    }

    public function permissions(RoleRequest $request, AdministrationRoleService $service): JsonResponse
    {
        return response()->json(['data' => $service->permissions($request->user())]);
    }
}
