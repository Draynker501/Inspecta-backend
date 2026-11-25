<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Support\Locations\LocationReadScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LocationHierarchyController extends TenantController
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user->hasPermission(Permission::PlantsView)) {
            abort(403);
        }

        LocationReadScope::rejectUnknownQuery($request, ['page', 'per_page', 'plant_id', 'area_id']);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'plant_id' => ['sometimes', 'uuid'],
            'area_id' => ['sometimes', 'uuid'],
        ]);

        $organizationId = app(TenantContext::class)->requireId();
        $hasVisibility = LocationReadScope::hasLocationVisibility($user);
        $assignedPlantIds = $hasVisibility
            ? []
            : $user->plants()->wherePivot('organization_id', $organizationId)->pluck('plants.id')->all();

        $rootQuery = Plant::query()
            ->where('organization_id', $organizationId);

        if (isset($validated['plant_id'])) {
            $rootQuery->whereKey($validated['plant_id']);
        }

        if (isset($validated['area_id'])) {
            $rootQuery->whereExists(function ($subquery) use ($user, $organizationId, $validated, $assignedPlantIds, $hasVisibility): void {
                $subquery->selectRaw('1')
                    ->from('areas')
                    ->whereColumn('areas.plant_id', 'plants.id')
                    ->where('areas.id', $validated['area_id'])
                    ->where('areas.organization_id', $organizationId)
                    ->whereNull('areas.deleted_at');

                if (! $hasVisibility) {
                    $subquery->where(function ($scope) use ($user, $organizationId, $assignedPlantIds): void {
                        $scope->whereExists(function ($inner) use ($user, $organizationId): void {
                            $inner->selectRaw('1')
                                ->from('user_areas')
                                ->whereColumn('user_areas.area_id', 'areas.id')
                                ->where('user_areas.user_id', $user->id)
                                ->where('user_areas.organization_id', $organizationId);
                        });

                        if ($assignedPlantIds !== []) {
                            $scope->orWhereIn('areas.plant_id', $assignedPlantIds);
                        }
                    });
                }
            });
        }

        if (! $hasVisibility) {
            $rootQuery->where(function ($scope) use ($user, $organizationId, $assignedPlantIds): void {
                $scope->whereIn('id', function ($subquery) use ($user, $organizationId): void {
                    $subquery->select('plant_id')
                        ->from('user_plants')
                        ->where('user_id', $user->id)
                        ->where('organization_id', $organizationId);
                });

                $scope->orWhereExists(function ($subquery) use ($user, $organizationId): void {
                    $subquery->selectRaw('1')
                        ->from('areas')
                        ->whereColumn('areas.plant_id', 'plants.id')
                        ->where('areas.organization_id', $organizationId)
                        ->whereNull('areas.deleted_at')
                        ->whereExists(function ($inner) use ($user, $organizationId): void {
                            $inner->selectRaw('1')
                                ->from('user_areas')
                                ->whereColumn('user_areas.area_id', 'areas.id')
                                ->where('user_areas.user_id', $user->id)
                                ->where('user_areas.organization_id', $organizationId);
                        });
                });

                if ($assignedPlantIds !== []) {
                    $scope->orWhereIn('id', $assignedPlantIds);
                }
            });
        }

        $perPage = $validated['per_page'] ?? 15;
        $page = $validated['page'] ?? 1;

        $rootPaginator = (clone $rootQuery)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $rootPlants = $rootPaginator->items();
        $rootIds = array_map(static fn (Plant $plant): string => (string) $plant->getKey(), $rootPlants);

        $areasQuery = Area::query()
            ->where('organization_id', $organizationId)
            ->whereIn('plant_id', $rootIds)
            ->whereNull('deleted_at');

        if (isset($validated['area_id'])) {
            $areasQuery->where('id', $validated['area_id']);
        }

        if (! $hasVisibility) {
            $areasQuery->where(function (Builder $scope) use ($user, $organizationId, $assignedPlantIds): void {
                $scope->whereExists(function ($subquery) use ($user, $organizationId): void {
                    $subquery->selectRaw('1')
                        ->from('user_areas')
                        ->whereColumn('user_areas.area_id', 'areas.id')
                        ->where('user_areas.user_id', $user->id)
                        ->where('user_areas.organization_id', $organizationId);
                });

                if ($assignedPlantIds !== []) {
                    $scope->orWhereIn('plant_id', $assignedPlantIds);
                }
            });
        }

        $areas = $areasQuery
            ->orderBy('name')
            ->orderBy('id')
            ->limit(1001)
            ->get();

        $areaIds = $areas->pluck('id')->all();

        $linesQuery = ProductionLine::query()
            ->where('organization_id', $organizationId)
            ->whereIn('area_id', $areaIds)
            ->whereNull('deleted_at');

        $lines = $linesQuery
            ->orderBy('name')
            ->orderBy('id')
            ->limit(1001)
            ->get();

        $nodeCount = count($rootIds) + $areas->count() + $lines->count();

        if ($nodeCount > 1000) {
            throw ValidationException::withMessages([
                'hierarchy' => 'La consulta supera el máximo de 1,000 nodos; reducir la paginación o utilizar filtros.',
            ]);
        }

        $linesByArea = $lines->groupBy('area_id');
        $areasByPlant = $areas->groupBy('plant_id');

        $data = [];

        foreach ($rootPlants as $plant) {
            $plantAreas = [];

            $plantAvailable = (bool) $plant->getAttribute('status');

            foreach ($areasByPlant->get((string) $plant->getKey(), collect()) as $area) {
                $areaLines = [];

                $areaAvailable = $plantAvailable
                    && (bool) $area->getAttribute('status');

                foreach ($linesByArea->get((string) $area->getKey(), collect()) as $line) {
                    $lineAvailable = $areaAvailable
                        && (bool) $line->getAttribute('status');

                    $areaLines[] = [
                        'id' => (string) $line->getKey(),
                        'name' => (string) $line->getAttribute('name'),
                        'code' => (string) $line->getAttribute('code'),
                        'status' => (bool) $line->getAttribute('status'),
                        'available' => $lineAvailable,
                    ];
                }

                $plantAreas[] = [
                    'id' => (string) $area->getKey(),
                    'name' => (string) $area->getAttribute('name'),
                    'code' => (string) $area->getAttribute('code'),
                    'type' => $area->getAttribute('type'),
                    'status' => (bool) $area->getAttribute('status'),
                    'available' => $areaAvailable,
                    'production_lines' => $areaLines,
                ];
            }

            $data[] = [
                'id' => (string) $plant->getKey(),
                'name' => (string) $plant->getAttribute('name'),
                'code' => (string) $plant->getAttribute('code'),
                'status' => (bool) $plant->getAttribute('status'),
                'available' => $plantAvailable,
                'areas' => $plantAreas,
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $rootPaginator->currentPage(),
                'per_page' => $rootPaginator->perPage(),
                'total' => $rootPaginator->total(),
                'last_page' => $rootPaginator->lastPage(),
            ],
        ]);
    }
}
