<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Http\Requests\StorePlantRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Requests\UpdateLocationStatusRequest;
use App\Models\Plant;
use App\Services\AdministrationLocationService;
use App\Support\Audit\AuditLogger;
use App\Support\Locations\LocationReadScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlantController extends TenantController
{
    public function store(StorePlantRequest $request)
    {
        $validated = $request->validated();
        $organizationId = app(TenantContext::class)->requireId();

        $plant = DB::transaction(function () use ($validated, $organizationId) {
            try {
                $plant = Plant::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organizationId,
                    'name' => $validated['name'],
                    'code' => $validated['code'],
                    'address' => $validated['address'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'status' => true,
                ]);
            } catch (QueryException $exception) {
                $info = $exception->errorInfo;
                $driver = DB::connection($exception->getConnectionName())->getDriverName();
                $sqliteCodeConflict = $driver === 'sqlite'
                    && ($info[0] ?? null) === '23000'
                    && (int) ($info[1] ?? 0) === 19
                    && ($info[2] ?? null) === 'UNIQUE constraint failed: plants.organization_id, plants.code';
                $mariaDbCodeConflict = $driver === 'mariadb'
                    && ($info[0] ?? null) === '23000'
                    && (int) ($info[1] ?? 0) === 1062
                    && is_string($info[2] ?? null)
                    && str_ends_with($info[2], " for key 'plants_org_code_unique'");

                if ($sqliteCodeConflict || $mariaDbCodeConflict) {
                    throw ValidationException::withMessages(['code' => 'El código ya está en uso.']);
                }

                throw $exception;
            }

            app(AuditLogger::class)->record('plant.created', $plant, [], [
                'name' => $plant->name,
                'code' => $plant->code,
                'status' => $plant->status,
            ]);

            return $plant;
        });

        return response()->json([
            'data' => [
                'id' => $plant->id,
                'organization_id' => $plant->organization_id,
                'name' => $plant->name,
                'code' => $plant->code,
                'address' => $plant->address,
                'description' => $plant->description,
                'status' => $plant->status,
            ],
        ], 201);
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Plant::class);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => [
                'sometimes',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
                        $fail('The '.$attribute.' field must be true or false.');
                    }
                },
            ],
            'q' => ['sometimes', 'string', 'max:100'],
            'include_hierarchy' => ['sometimes'],
        ]);

        $user = $request->user();
        $organizationId = app(TenantContext::class)->requireId();
        $perPage = $validated['per_page'] ?? 15;

        $query = Plant::query()->where('organization_id', $organizationId);

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

        if (! $user->hasPermission(Permission::LocationsViewAll)) {
            $query->whereIn('id', function ($subquery) use ($user, $organizationId): void {
                $subquery->select('plant_id')
                    ->from('user_plants')
                    ->where('user_id', $user->id)
                    ->where('organization_id', $organizationId);
            });
        }

        $plants = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $plants->items(),
            'meta' => [
                'current_page' => $plants->currentPage(),
                'per_page' => $plants->perPage(),
                'total' => $plants->total(),
                'last_page' => $plants->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $plantId)
    {
        $user = $request->user();

        if (! $user->hasPermission(Permission::PlantsView)) {
            abort(403);
        }

        LocationReadScope::rejectUnknownQuery($request, []);
        $organizationId = app(TenantContext::class)->requireId();

        $plant = LocationReadScope::plants($user, $organizationId)
            ->whereKey($plantId)
            ->first();

        if ($plant === null || ! LocationReadScope::canViewPlant($user, (string) $plant->getKey())) {
            abort(404);
        }

        return response()->json(['data' => $plant->toArray()]);
    }

    public function update(
        UpdateLocationRequest $request,
        string $plantId,
        AdministrationLocationService $service,
    ) {
        $plant = $service->updatePlant(
            $plantId,
            $request->validated(),
        );

        return response()->json([
            'data' => $plant->toArray(),
        ]);
    }

    public function updateStatus(
        UpdateLocationStatusRequest $request,
        string $plantId,
        AdministrationLocationService $service,
    ) {
        $plant = $service->updatePlantStatus(
            $plantId,
            $request->validated('status'),
        );

        return response()->json([
            'data' => $plant->toArray(),
        ]);
    }

    public function areas(Request $request, string $plantId)
    {
        $user = $request->user();

        if (! $user->hasPermission(Permission::PlantsView)) {
            abort(403);
        }

        $organizationId = app(TenantContext::class)->requireId();

        $plant = Plant::query()
            ->where('organization_id', $organizationId)
            ->whereKey($plantId)
            ->first();

        if ($plant === null) {
            abort(404);
        }

        LocationReadScope::rejectUnknownQuery($request, ['page', 'per_page', 'status', 'q', 'include_hierarchy']);
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => [
                'sometimes',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
                        $fail('The '.$attribute.' field must be true or false.');
                    }
                },
            ],
            'q' => ['sometimes', 'string', 'max:100'],
            'include_hierarchy' => ['sometimes'],
        ]);

        $baseQuery = LocationReadScope::areasForPlant($user, $organizationId, $plantId);

        $hasNavigation = LocationReadScope::hasLocationVisibility($user)
            || $user->hasPlantAccess($plantId);

        if (! $hasNavigation && (clone $baseQuery)->count() === 0) {
            abort(404);
        }

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
        $areas = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => collect($areas->items())->map->toArray()->all(),
            'meta' => [
                'current_page' => $areas->currentPage(),
                'per_page' => $areas->perPage(),
                'total' => $areas->total(),
                'last_page' => $areas->lastPage(),
            ],
        ]);
    }
}
