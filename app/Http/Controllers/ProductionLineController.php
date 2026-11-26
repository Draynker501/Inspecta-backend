<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Requests\UpdateLocationStatusRequest;
use App\Models\ProductionLine;
use App\Services\AdministrationLocationService;
use App\Support\Locations\LocationReadScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

class ProductionLineController extends TenantController
{
    public function show(Request $request, string $productionLineId)
    {
        $user = $request->user();

        if (! $user->hasPermission(Permission::PlantsView)) {
            abort(403);
        }

        LocationReadScope::rejectUnknownQuery($request, []);
        $organizationId = app(TenantContext::class)->requireId();

        $line = ProductionLine::query()
            ->where('organization_id', $organizationId)
            ->whereKey($productionLineId)
            ->first();

        if ($line === null || $line->area === null || $line->area->plant === null
            || ! LocationReadScope::canViewLine($user, $line)) {
            abort(404);
        }

        return response()->json(['data' => $line->toArray()]);
    }

    public function update(
        UpdateLocationRequest $request,
        string $productionLineId,
        AdministrationLocationService $service,
    ) {
        $line = $service->updateProductionLine(
            $productionLineId,
            $request->validated(),
        );

        return response()->json([
            'data' => $line->toArray(),
        ]);
    }

    public function updateStatus(
        UpdateLocationStatusRequest $request,
        string $productionLineId,
        AdministrationLocationService $service,
    ) {
        $line = $service->updateProductionLineStatus(
            $productionLineId,
            $request->validated('status'),
        );

        return response()->json([
            'data' => $line->toArray(),
        ]);
    }
}
