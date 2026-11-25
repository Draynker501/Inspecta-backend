<?php

namespace App\Services;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Inspection;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ReportService
{
    private const INSPECTION_STATUSES = [
        'draft',
        'completed',
        'confirmed',
        'rejected',
    ];

    private const INCIDENT_STATUSES = [
        'open',
        'in_progress',
        'resolved',
        'closed',
    ];

    private const RISK_LEVELS = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    private const ACTION_STATUSES = [
        'pending',
        'in_progress',
        'completed',
        'verified',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Genera el reporte consolidado de la organización activa.
     */
    public function getSummary(
        User $user,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $filters = []
    ): array {
        $organizationId = $this->tenantContext->requireId();

        $canViewAll = $user->hasPermission(
            Permission::ReportsView
        );

        $canViewOwn = $user->hasPermission(
            Permission::ReportsViewOwn
        );

        abort_unless($canViewAll || $canViewOwn, 403);

        $ownOnly = ! $canViewAll;

        $gate = Gate::forUser($user);

        $canViewInspections = $gate->allows(
            'viewAny',
            Inspection::class
        );

        $canViewIncidents = $gate->allows(
            'viewAny',
            Incident::class
        );

        $canViewActions = $gate->allows(
            'viewAny',
            CorrectiveAction::class
        );

        $inspections = null;
        $incidents = null;
        $correctiveActions = null;

        /*
        |--------------------------------------------------------------------------
        | Inspecciones
        |--------------------------------------------------------------------------
        */

        if ($canViewInspections) {
            $query = Inspection::query()
                ->where('organization_id', $organizationId);

            if (
                $ownOnly
                || ! $user->hasPermission(
                    Permission::InspectionsManage
                )
            ) {
                $query->where('user_id', $user->id);
            }

            $this->restrictLocations(
                $query,
                $user,
                $organizationId
            );

            $this->applyLocationFilters(
                $query,
                $filters
            );

            $this->applyPeriod(
                $query,
                'started_at',
                $start,
                $end
            );

            $inspections = $this->inspectionStatistics(
                $query
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Incidencias
        |--------------------------------------------------------------------------
        */

        if ($canViewIncidents) {
            $query = Incident::query()
                ->where('organization_id', $organizationId);

            if ($ownOnly) {
                $query->where('reported_by', $user->id);
            }

            $this->restrictLocations(
                $query,
                $user,
                $organizationId
            );

            $this->applyLocationFilters(
                $query,
                $filters
            );

            $this->applyPeriod(
                $query,
                'reported_at',
                $start,
                $end
            );

            $incidents = $this->incidentStatistics(
                $query,
                $organizationId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Acciones correctivas
        |--------------------------------------------------------------------------
        */

        if ($canViewActions) {
            $query = CorrectiveAction::query()
                ->where('organization_id', $organizationId)
                ->whereHas(
                    'incident',
                    function (Builder $incidentQuery) use (
                        $user,
                        $organizationId,
                        $filters
                    ): void {
                        $incidentQuery->where(
                            'organization_id',
                            $organizationId
                        );

                        $this->restrictLocations(
                            $incidentQuery,
                            $user,
                            $organizationId
                        );

                        $this->applyLocationFilters(
                            $incidentQuery,
                            $filters
                        );
                    }
                );

            if ($ownOnly) {
                $query->where('assigned_to', $user->id);
            }

            $this->applyPeriod(
                $query,
                'created_at',
                $start,
                $end
            );

            $correctiveActions =
                $this->correctiveActionStatistics($query);
        }

        return [
            'organization_id' => $organizationId,

            'period' => [
                'date_from' => $start->toDateString(),
                'date_to' => $end->subDay()->toDateString(),
            ],

            'filters' => [
                'plant_id' => $filters['plant_id'] ?? null,
                'area_id' => $filters['area_id'] ?? null,
                'production_line_id' => $filters['production_line_id'] ?? null,
            ],

            'scope' => $ownOnly ? 'own' : 'all',

            'capabilities' => [
                'inspections' => $canViewInspections,
                'incidents' => $canViewIncidents,
                'corrective_actions' => $canViewActions,
            ],

            'inspections' => $inspections,
            'incidents' => $incidents,
            'corrective_actions' => $correctiveActions,
        ];
    }

    /**
     * Estadísticas de las inspecciones iniciadas
     * durante el periodo seleccionado.
     */
    private function inspectionStatistics(
        Builder $query
    ): array {
        $statuses = $this->groupedCounts(
            $query,
            'status',
            self::INSPECTION_STATUSES
        );

        $total = array_sum($statuses);

        $finalized =
            $statuses['completed']
            + $statuses['confirmed']
            + $statuses['rejected'];

        $reviewed =
            $statuses['confirmed']
            + $statuses['rejected'];

        return [
            'total' => $total,

            'finalized' => $finalized,

            'reviewed' => $reviewed,

            'completion_rate' => $this->percentage(
                $finalized,
                $total
            ),

            'approval_rate' => $this->percentage(
                $statuses['confirmed'],
                $reviewed
            ),

            'by_status' => $statuses,
        ];
    }

    /**
     * Estadísticas de incidencias por estado,
     * severidad y área.
     */
    private function incidentStatistics(
        Builder $query,
        string $organizationId
    ): array {
        $statuses = $this->groupedCounts(
            $query,
            'status',
            self::INCIDENT_STATUSES
        );

        $riskLevels = $this->groupedCounts(
            $query,
            'risk_level',
            self::RISK_LEVELS
        );

        $total = array_sum($statuses);

        $areas = (clone $query)
            ->select('area_id')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('area_id')
            ->orderByDesc('total')
            ->orderBy('area_id')
            ->get();

        $areaIds = $areas
            ->pluck('area_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $areaNames = Area::query()
            ->withTrashed()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $areaIds)
            ->pluck('name', 'id');

        $byArea = $areas->map(
            function ($item) use ($areaNames, $total): array {
                $count = (int) $item->total;

                return [
                    'area_id' => $item->area_id,

                    'area_name' => $areaNames->get(
                        $item->area_id,
                        'Área no disponible'
                    ),

                    'count' => $count,

                    'percentage' => $this->percentage(
                        $count,
                        $total
                    ),
                ];
            }
        )->values()->all();

        return [
            'total' => $total,

            'critical' => $riskLevels['critical'],

            'open' => $statuses['open']
                + $statuses['in_progress'],

            'resolved' => $statuses['resolved']
                + $statuses['closed'],

            'by_status' => $statuses,

            'by_risk_level' => $riskLevels,

            'by_area' => $byArea,
        ];
    }

    /**
     * Estadísticas de acciones correctivas creadas
     * durante el periodo seleccionado.
     */
    private function correctiveActionStatistics(
        Builder $query
    ): array {
        $statuses = $this->groupedCounts(
            $query,
            'status',
            self::ACTION_STATUSES
        );

        $total = array_sum($statuses);

        $overdue = (clone $query)
            ->whereIn('status', [
                'pending',
                'in_progress',
            ])
            ->whereNotNull('due_date')
            ->where(
                'due_date',
                '<',
                CarbonImmutable::today()->toDateString()
            )
            ->count();

        return [
            'total' => $total,

            'verified' => $statuses['verified'],

            'pending' => $statuses['pending']
                + $statuses['in_progress'],

            'overdue' => $overdue,

            'verification_rate' => $this->percentage(
                $statuses['verified'],
                $total
            ),

            'by_status' => $statuses,
        ];
    }

    /**
     * Agrupa registros por una columna y garantiza
     * que todas las categorías esperadas estén presentes.
     */
    private function groupedCounts(
        Builder $query,
        string $column,
        array $categories
    ): array {
        $counts = (clone $query)
            ->select($column)
            ->selectRaw('COUNT(*) AS total')
            ->groupBy($column)
            ->pluck('total', $column);

        $result = [];

        foreach ($categories as $category) {
            $result[$category] = (int) $counts->get(
                $category,
                0
            );
        }

        return $result;
    }

    /**
     * Restringe los registros a las plantas o áreas
     * asignadas al usuario dentro de la organización.
     */
    private function restrictLocations(
        Builder $query,
        User $user,
        string $organizationId
    ): void {
        $query->where(
            function (Builder $scope) use (
                $user,
                $organizationId
            ): void {
                $scope->whereIn(
                    'plant_id',
                    function ($subquery) use (
                        $user,
                        $organizationId
                    ): void {
                        $subquery
                            ->select('plant_id')
                            ->from('user_plants')
                            ->where(
                                'organization_id',
                                $organizationId
                            )
                            ->where('user_id', $user->id);
                    }
                )->orWhereIn(
                    'area_id',
                    function ($subquery) use (
                        $user,
                        $organizationId
                    ): void {
                        $subquery
                            ->select('area_id')
                            ->from('user_areas')
                            ->where(
                                'organization_id',
                                $organizationId
                            )
                            ->where('user_id', $user->id);
                    }
                );
            }
        );
    }

    /**
     * Aplica los filtros opcionales de ubicación.
     */
    private function applyLocationFilters(
        Builder $query,
        array $filters
    ): void {
        foreach ([
            'plant_id',
            'area_id',
            'production_line_id',
        ] as $column) {
            if (isset($filters[$column])) {
                $query->where(
                    $column,
                    $filters[$column]
                );
            }
        }
    }

    /**
     * Fecha inicial inclusiva y fecha final exclusiva.
     */
    private function applyPeriod(
        Builder $query,
        string $column,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): void {
        $query
            ->where($column, '>=', $start)
            ->where($column, '<', $end);
    }

    /**
     * Devuelve null cuando no existen registros
     * suficientes para calcular el porcentaje.
     */
    private function percentage(
        int $numerator,
        int $denominator
    ): ?float {
        if ($denominator === 0) {
            return null;
        }

        return round(
            ($numerator / $denominator) * 100,
            2
        );
    }
}
