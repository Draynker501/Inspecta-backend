<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Area;
use App\Support\Locations\LocationReadScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

class AreaController extends TenantController
{
    public function show(Request $request, string $areaId)
    {
        $user = $request->user();

        if (! $user->hasPermission(Permission::PlantsView)) {
            abort(403);
        }

        LocationReadScope::rejectUnknownQuery($request, []);
        $organizationId = app(TenantContext::class)->requireId();

        $area = Area::query()
            ->where('organization_id', $organizationId)
            ->whereKey($areaId)
            ->first();

        if ($area === null || $area->plant === null || ! LocationReadScope::canViewArea($user, $area)) {
            abort(404);
        }

        return response()->json(['data' => $area->toArray()]);
    }

    public function lines(Request $request, string $areaId)
    {
        $user = $request->user();

        if (! $user->hasPermission(Permission::PlantsView)) {
            abort(403);
        }

        $organizationId = app(TenantContext::class)->requireId();

        $area = Area::query()
            ->where('organization_id', $organizationId)
            ->whereKey($areaId)
            ->first();

        if ($area === null || $area->plant === null) {
            abort(404);
        }

        $plantId = (string) $area->getAttribute('plant_id');

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
                    $fail('The '.$attribute.' field must be true or false.');
                }
            }],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);

        if (! LocationReadScope::canViewArea($user, $area)) {
            abort(404);
        }

        $baseQuery = LocationReadScope::linesForArea($user, $organizationId, $areaId, $plantId);

        $query = clone $baseQuery;

        if (array_key_exists('status', $validated)) {
            $query->where('status', filter_var($validated['status'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($validated['q'])) {
            $escaped = LocationReadScope::escapeLike($validated['q']);
            $query->where(function ($builder) use ($escaped): void {
                $builder->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$escaped.'%'])
                    ->orWhereRaw("code LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
            });
        }

        $perPage = $validated['per_page'] ?? 15;
        $lines = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => collect($lines->items())->map->toArray()->all(),
            'meta' => [
                'current_page' => $lines->currentPage(),
                'per_page' => $lines->perPage(),
                'total' => $lines->total(),
                'last_page' => $lines->lastPage(),
            ],
        ]);
    }
}
