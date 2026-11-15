<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductionLineRequest;
use App\Models\Area;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductionLineStoreController extends TenantController
{
    public function store(StoreProductionLineRequest $request, string $areaId)
    {
        $validated = $request->validated();
        $organizationId = app(TenantContext::class)->requireId();

        // Referencia inicial del área, aislada por tenant, para obtener la planta
        // padre antes de adquirir los bloqueos en el orden aprobado.
        $initialArea = Area::query()
            ->where('organization_id', $organizationId)
            ->whereKey($areaId)
            ->firstOrFail();

        $line = DB::transaction(function () use ($validated, $organizationId, $areaId, $initialArea) {
            // Orden de bloqueo aprobado: primero la planta, luego el área.
            $plant = Plant::query()
                ->where('organization_id', $organizationId)
                ->whereKey($initialArea->plant_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Revalidar el área bajo bloqueo: debe seguir existiendo, pertenecer
            // al tenant y a la planta bloqueada, y no estar eliminada.
            $area = Area::query()
                ->where('organization_id', $organizationId)
                ->whereKey($areaId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($area->plant_id !== $plant->id) {
                throw new ModelNotFoundException;
            }

            if (! $area->status) {
                throw ValidationException::withMessages(['areaId' => 'El área está inactiva.']);
            }

            if (! $plant->status) {
                throw ValidationException::withMessages(['plantId' => 'La planta está inactiva.']);
            }

            try {
                $line = ProductionLine::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organizationId,
                    'area_id' => $area->id,
                    'name' => $validated['name'],
                    'code' => $validated['code'],
                    'description' => $validated['description'] ?? null,
                    'status' => true,
                ]);
            } catch (QueryException $exception) {
                $info = $exception->errorInfo;
                $driver = DB::connection($exception->getConnectionName())->getDriverName();
                $sqliteConflict = $driver === 'sqlite'
                    && ($info[0] ?? null) === '23000'
                    && (int) ($info[1] ?? 0) === 19
                    && ($info[2] ?? null) === 'UNIQUE constraint failed: production_lines.area_id, production_lines.code';
                $mariaConflict = in_array($driver, ['mariadb', 'mysql'], true)
                    && ($info[0] ?? null) === '23000'
                    && (int) ($info[1] ?? 0) === 1062
                    && is_string($info[2] ?? null)
                    && preg_match("/ for key '(?:[^']+\\.)?prod_lines_area_code_unique'\\z/", $info[2]) === 1;
                if ($sqliteConflict || $mariaConflict) {
                    throw ValidationException::withMessages(['code' => 'El código ya está en uso.']);
                }
                throw $exception;
            }

            app(AuditLogger::class)->record('production_line.created', $line, [], [
                'area_id' => $line->area_id,
                'name' => $line->name,
                'code' => $line->code,
                'status' => $line->status,
            ]);

            return $line;
        });

        return response()->json(['data' => [
            'id' => $line->id,
            'organization_id' => $line->organization_id,
            'area_id' => $line->area_id,
            'name' => $line->name,
            'code' => $line->code,
            'description' => $line->description,
            'status' => $line->status,
        ]], 201);
    }
}
