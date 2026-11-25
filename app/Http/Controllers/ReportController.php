<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Services\ReportService;
use App\Support\Locations\LocationReadScope;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportController extends TenantController
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Obtiene el reporte estadístico consolidado.
     */
    public function summary(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user->hasPermission(Permission::ReportsView)
                || $user->hasPermission(
                    Permission::ReportsViewOwn
                ),
            403
        );

        LocationReadScope::rejectUnknownQuery(
            $request,
            [
                'date_from',
                'date_to',
                'plant_id',
                'area_id',
                'production_line_id',
            ]
        );

        $validated = $request->validate([
            'date_from' => [
                'required',
                'string',
                'date_format:Y-m-d',
            ],

            'date_to' => [
                'required',
                'string',
                'date_format:Y-m-d',
                'after_or_equal:date_from',
            ],

            'plant_id' => [
                'sometimes',
                'required',
                'uuid',
            ],

            'area_id' => [
                'sometimes',
                'required',
                'uuid',
            ],

            'production_line_id' => [
                'sometimes',
                'required',
                'uuid',
            ],
        ], [
            'date_from.required' => 'Debes proporcionar la fecha inicial.',

            'date_from.date_format' => 'La fecha inicial debe tener el formato YYYY-MM-DD.',

            'date_to.required' => 'Debes proporcionar la fecha final.',

            'date_to.date_format' => 'La fecha final debe tener el formato YYYY-MM-DD.',

            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',

            'plant_id.uuid' => 'El identificador de la planta no es válido.',

            'area_id.uuid' => 'El identificador del área no es válido.',

            'production_line_id.uuid' => 'El identificador de la línea de producción no es válido.',
        ]);

        $organizationId =
            $this->tenantContext->requireId();

        $filters = [
            'plant_id' => $validated['plant_id'] ?? null,

            'area_id' => $validated['area_id'] ?? null,

            'production_line_id' => $validated['production_line_id'] ?? null,
        ];

        $this->validateLocationFilters(
            $filters,
            $organizationId
        );

        $start = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $validated['date_from']
        )->startOfDay();

        $end = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $validated['date_to']
        )->startOfDay()->addDay();

        $report = $this->reportService->getSummary(
            $user,
            $start,
            $end,
            $filters
        );

        return response()->json($report);
    }

    /**
     * Comprueba que los filtros pertenezcan a la
     * organización activa y sean coherentes entre sí.
     */
    private function validateLocationFilters(
        array $filters,
        string $organizationId
    ): void {
        $plant = null;
        $area = null;
        $productionLine = null;

        if (isset($filters['plant_id'])) {
            $plant = Plant::query()
                ->where('organization_id', $organizationId)
                ->find($filters['plant_id']);

            if ($plant === null) {
                throw ValidationException::withMessages([
                    'plant_id' => 'La planta seleccionada no es válida.',
                ]);
            }
        }

        if (isset($filters['area_id'])) {
            $area = Area::query()
                ->where('organization_id', $organizationId)
                ->find($filters['area_id']);

            if ($area === null) {
                throw ValidationException::withMessages([
                    'area_id' => 'El área seleccionada no es válida.',
                ]);
            }

            if (
                $plant !== null
                && $area->plant_id !== $plant->id
            ) {
                throw ValidationException::withMessages([
                    'area_id' => 'El área no pertenece a la planta seleccionada.',
                ]);
            }
        }

        if (isset($filters['production_line_id'])) {
            $productionLine = ProductionLine::query()
                ->where('organization_id', $organizationId)
                ->find($filters['production_line_id']);

            if ($productionLine === null) {
                throw ValidationException::withMessages([
                    'production_line_id' => 'La línea de producción seleccionada no es válida.',
                ]);
            }

            if (
                $area !== null
                && $productionLine->area_id !== $area->id
            ) {
                throw ValidationException::withMessages([
                    'production_line_id' => 'La línea de producción no pertenece al área seleccionada.',
                ]);
            }

            if ($plant !== null) {
                $lineArea = Area::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->find($productionLine->area_id);

                if (
                    $lineArea === null
                    || $lineArea->plant_id !== $plant->id
                ) {
                    throw ValidationException::withMessages([
                        'production_line_id' => 'La línea de producción no pertenece a la planta seleccionada.',
                    ]);
                }
            }
        }
    }
}
