<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Http\Requests\StorePlantRequest;
use App\Models\Plant;
use App\Support\Audit\AuditLogger;
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

        return response()->json(['data' => [
            'id' => $plant->id,
            'organization_id' => $plant->organization_id,
            'name' => $plant->name,
            'code' => $plant->code,
            'address' => $plant->address,
            'description' => $plant->description,
            'status' => $plant->status,
        ]], 201);
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Plant::class);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $organizationId = app(TenantContext::class)->requireId();
        $perPage = $validated['per_page'] ?? 15;

        $query = Plant::query()->where('organization_id', $organizationId);

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
}
