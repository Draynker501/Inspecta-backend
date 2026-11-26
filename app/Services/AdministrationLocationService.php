<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Organization;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdministrationLocationService
{
    private const PLANT_FIELDS = [
        'name',
        'code',
        'address',
        'description',
    ];

    private const AREA_FIELDS = [
        'name',
        'code',
        'type',
        'description',
    ];

    private const PRODUCTION_LINE_FIELDS = [
        'name',
        'code',
        'description',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function updatePlant(
        string $plantId,
        array $attributes,
    ): Plant {
        return DB::transaction(function () use ($plantId, $attributes): Plant {
            $this->authorizeManagement();

            $plant = $this->findPlant($plantId);

            $this->applyChanges(
                $plant,
                $attributes,
                self::PLANT_FIELDS,
                'plant.updated',
            );

            return $plant;
        });
    }

    public function updatePlantStatus(
        string $plantId,
        bool $status,
    ): Plant {
        return DB::transaction(function () use ($plantId, $status): Plant {
            $this->authorizeManagement();

            $plant = $this->findPlant($plantId);

            $this->applyChanges(
                $plant,
                ['status' => $status],
                ['status'],
                'plant.status_updated',
            );

            return $plant;
        });
    }

    public function updateArea(
        string $areaId,
        array $attributes,
    ): Area {
        return DB::transaction(function () use ($areaId, $attributes): Area {
            $this->authorizeManagement();

            $area = $this->findArea($areaId);

            $this->applyChanges(
                $area,
                $attributes,
                self::AREA_FIELDS,
                'area.updated',
            );

            return $area;
        });
    }

    public function updateAreaStatus(
        string $areaId,
        bool $status,
    ): Area {
        return DB::transaction(function () use ($areaId, $status): Area {
            $this->authorizeManagement();

            $area = $this->findArea($areaId);

            $this->applyChanges(
                $area,
                ['status' => $status],
                ['status'],
                'area.status_updated',
            );

            return $area;
        });
    }

    public function updateProductionLine(
        string $productionLineId,
        array $attributes,
    ): ProductionLine {
        return DB::transaction(function () use (
            $productionLineId,
            $attributes
        ): ProductionLine {
            $this->authorizeManagement();

            $line = $this->findProductionLine($productionLineId);

            $this->applyChanges(
                $line,
                $attributes,
                self::PRODUCTION_LINE_FIELDS,
                'production_line.updated',
            );

            return $line;
        });
    }

    public function updateProductionLineStatus(
        string $productionLineId,
        bool $status,
    ): ProductionLine {
        return DB::transaction(function () use (
            $productionLineId,
            $status
        ): ProductionLine {
            $this->authorizeManagement();

            $line = $this->findProductionLine($productionLineId);

            $this->applyChanges(
                $line,
                ['status' => $status],
                ['status'],
                'production_line.status_updated',
            );

            return $line;
        });
    }

    private function authorizeManagement(): void
    {
        Gate::authorize('create', Plant::class);

        $organizationId = $this->tenantContext->requireId();

        Organization::query()
            ->whereKey($organizationId)
            ->lockForUpdate()
            ->firstOrFail();

        $user = Auth::user();

        abort_if($user === null, 401);

        $this->tenantContext->flushPermissionCache(
            $user->id,
            $organizationId,
        );

        Gate::authorize('create', Plant::class);
    }

    private function findPlant(string $plantId): Plant
    {
        return Plant::query()
            ->where(
                'organization_id',
                $this->tenantContext->requireId(),
            )
            ->whereKey($plantId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function findArea(string $areaId): Area
    {
        $organizationId = $this->tenantContext->requireId();

        $initialArea = Area::query()
            ->where('organization_id', $organizationId)
            ->whereKey($areaId)
            ->firstOrFail();

        $plant = $this->findPlant(
            (string) $initialArea->plant_id,
        );

        return Area::query()
            ->where('organization_id', $organizationId)
            ->where('plant_id', $plant->id)
            ->whereKey($areaId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function findProductionLine(
        string $productionLineId,
    ): ProductionLine {
        $organizationId = $this->tenantContext->requireId();

        $initialLine = ProductionLine::query()
            ->where('organization_id', $organizationId)
            ->whereKey($productionLineId)
            ->firstOrFail();

        $area = $this->findArea(
            (string) $initialLine->area_id,
        );

        return ProductionLine::query()
            ->where('organization_id', $organizationId)
            ->where('area_id', $area->id)
            ->whereKey($productionLineId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function applyChanges(
        Model $model,
        array $attributes,
        array $allowedFields,
        string $action,
    ): void {
        $unexpectedFields = array_diff(
            array_keys($attributes),
            $allowedFields,
        );

        if ($unexpectedFields !== []) {
            $errors = [];

            foreach ($unexpectedFields as $field) {
                $errors[$field] = 'Este campo no está permitido.';
            }

            throw ValidationException::withMessages($errors);
        }

        $attributes = Arr::only($attributes, $allowedFields);

        if (is_string($attributes['code'] ?? null)) {
            $attributes['code'] = strtoupper(
                trim($attributes['code']),
            );
        }

        $oldValues = $model->only($allowedFields);

        $model->fill($attributes);

        $changedFields = array_keys($model->getDirty());

        if ($changedFields === []) {
            return;
        }

        try {
            $model->save();
        } catch (QueryException $exception) {
            if ($this->isCodeConflict($exception, $model)) {
                throw ValidationException::withMessages([
                    'code' => 'El código ya está en uso.',
                ]);
            }

            throw $exception;
        }

        $this->auditLogger->record(
            $action,
            $model,
            Arr::only($oldValues, $changedFields),
            $model->only($changedFields),
        );
    }

    private function isCodeConflict(
        QueryException $exception,
        Model $model,
    ): bool {
        $constraints = match (true) {
            $model instanceof Plant => [
                'sqlite' => 'UNIQUE constraint failed: plants.organization_id, plants.code',
                'maria' => 'plants_org_code_unique',
            ],

            $model instanceof Area => [
                'sqlite' => 'UNIQUE constraint failed: areas.plant_id, areas.code',
                'maria' => 'areas_plant_code_unique',
            ],

            $model instanceof ProductionLine => [
                'sqlite' => 'UNIQUE constraint failed: production_lines.area_id, production_lines.code',
                'maria' => 'prod_lines_area_code_unique',
            ],

            default => null,
        };

        if ($constraints === null) {
            return false;
        }

        $info = $exception->errorInfo;

        $driver = DB::connection(
            $exception->getConnectionName(),
        )->getDriverName();

        $sqlState = $info[0] ?? null;
        $errorCode = (int) ($info[1] ?? 0);
        $message = $info[2] ?? null;

        if ($sqlState !== '23000' || ! is_string($message)) {
            return false;
        }

        if ($driver === 'sqlite') {
            return $errorCode === 19
                && $message === $constraints['sqlite'];
        }

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            $constraint = preg_quote(
                $constraints['maria'],
                '/',
            );

            return $errorCode === 1062
                && preg_match(
                    "/ for key '(?:[^']+\\.)?".$constraint."'\\z/",
                    $message,
                ) === 1;
        }

        return false;
    }
}
