<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAreaRequest;
use App\Models\Area;
use App\Models\Plant;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AreaStoreController extends TenantController
{
    public function store(StoreAreaRequest $request, string $plantId)
    {
        $validated = $request->validated();
        $organizationId = app(TenantContext::class)->requireId();
        $area = DB::transaction(function () use ($validated, $organizationId, $plantId) {
            $plant = Plant::query()->where('organization_id', $organizationId)
                ->whereKey($plantId)->lockForUpdate()->firstOrFail();
            if (! $plant->status) {
                throw ValidationException::withMessages(['plantId' => 'La planta está inactiva.']);
            }

            try {
                $area = Area::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organizationId,
                    'plant_id' => $plant->id,
                    'name' => $validated['name'],
                    'code' => $validated['code'],
                    'type' => $validated['type'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'status' => true,
                ]);
            } catch (QueryException $exception) {
                $info = $exception->errorInfo;
                $driver = DB::connection($exception->getConnectionName())->getDriverName();
                $sqliteConflict = $driver === 'sqlite'
                    && ($info[0] ?? null) === '23000'
                    && (int) ($info[1] ?? 0) === 19
                    && ($info[2] ?? null) === 'UNIQUE constraint failed: areas.plant_id, areas.code';
                $mariaConflict = in_array($driver, ['mariadb', 'mysql'], true)
                    && ($info[0] ?? null) === '23000'
                    && (int) ($info[1] ?? 0) === 1062
                    && is_string($info[2] ?? null)
                    && preg_match("/ for key '(?:[^']+\\.)?areas_plant_code_unique'\\z/", $info[2]) === 1;
                if ($sqliteConflict || $mariaConflict) {
                    throw ValidationException::withMessages(['code' => 'El código ya está en uso.']);
                }
                throw $exception;
            }

            app(AuditLogger::class)->record('area.created', $area, [], [
                'plant_id' => $area->plant_id,
                'name' => $area->name,
                'code' => $area->code,
                'status' => $area->status,
            ]);

            return $area;
        });

        return response()->json(['data' => [
            'id' => $area->id,
            'organization_id' => $area->organization_id,
            'plant_id' => $area->plant_id,
            'name' => $area->name,
            'code' => $area->code,
            'type' => $area->type,
            'description' => $area->description,
            'status' => $area->status,
        ]], 201);
    }
}
