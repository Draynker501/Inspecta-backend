<?php

namespace App\Services;

use App\Authorization\Permission;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Inspection;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DashboardService
{
    private const RISK_LEVELS = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    private const OPEN_INCIDENT_STATUSES = [
        'open',
        'in_progress',
    ];

    private const PENDING_ACTION_STATUSES = [
        'pending',
        'in_progress',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Genera el resumen estadístico del Dashboard.
     */
    public function getDashboard(
        User $user,
        CarbonImmutable $period
    ): array {
        $organizationId = $this->tenantContext->requireId();

        $start = $period->startOfMonth();
        $end = $start->addMonth();

        $previousStart = $start->subMonth();
        $previousEnd = $start;

        $today = CarbonImmutable::today();

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

        $summary = [];
        $charts = [];
        $recentInspections = [];
        $pendingCorrectiveActions = [];

        /*
        |--------------------------------------------------------------------------
        | Inspecciones
        |--------------------------------------------------------------------------
        */

        if ($canViewInspections) {
            $inspectionQuery = $this->inspectionQuery(
                $user,
                $organizationId
            );

            $currentApproval = $this->approvalStatistics(
                $inspectionQuery,
                $start,
                $end
            );

            $previousApproval = $this->approvalStatistics(
                $inspectionQuery,
                $previousStart,
                $previousEnd
            );

            $currentTotal = $this->countByPeriod(
                $inspectionQuery,
                'started_at',
                $start,
                $end
            );

            $previousTotal = $this->countByPeriod(
                $inspectionQuery,
                'started_at',
                $previousStart,
                $previousEnd
            );

            $approvalChange = null;

            if (
                $currentApproval['rate'] !== null
                && $previousApproval['rate'] !== null
            ) {
                $approvalChange = round(
                    $currentApproval['rate']
                    - $previousApproval['rate'],
                    2
                );
            }

            $summary['approval_rate'] = [
                'value' => $currentApproval['rate'],
                'previous_value' => $previousApproval['rate'],
                'change' => $approvalChange,
                'approved' => $currentApproval['approved'],
                'rejected' => $currentApproval['rejected'],
                'reviewed' => $currentApproval['reviewed'],
            ];

            $summary['inspections'] = [
                'value' => $currentTotal,
                'previous_value' => $previousTotal,
                'change' => $currentTotal - $previousTotal,
            ];

            $charts['approval_trend'] = $this->approvalTrend(
                $inspectionQuery,
                $start
            );

            $recentInspections = $this->recentInspections(
                $inspectionQuery,
                $organizationId,
                $start,
                $end
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Incidencias
        |--------------------------------------------------------------------------
        */

        if ($canViewIncidents) {
            $incidentQuery = $this->incidentQuery(
                $user,
                $organizationId
            );

            $openIncidents = (clone $incidentQuery)
                ->whereIn(
                    'status',
                    self::OPEN_INCIDENT_STATUSES
                );

            $summary['open_incidents'] = [
                'value' => (clone $openIncidents)->count(),
                'critical' => (clone $openIncidents)
                    ->where('risk_level', 'critical')
                    ->count(),
            ];

            $charts['incidents_by_risk'] = $this->incidentsByRisk(
                $incidentQuery,
                $start,
                $end
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Acciones correctivas
        |--------------------------------------------------------------------------
        */

        if ($canViewActions) {
            $actionQuery = $this->correctiveActionQuery(
                $user,
                $organizationId
            );

            $pendingActions = (clone $actionQuery)
                ->whereIn(
                    'status',
                    self::PENDING_ACTION_STATUSES
                );

            $summary['pending_corrective_actions'] = [
                'value' => (clone $pendingActions)->count(),
                'overdue' => (clone $pendingActions)
                    ->whereNotNull('due_date')
                    ->where(
                        'due_date',
                        '<',
                        $today->toDateString()
                    )
                    ->count(),
            ];

            $pendingCorrectiveActions =
                $this->pendingCorrectiveActions(
                    $pendingActions,
                    $organizationId,
                    $today
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Respuesta
        |--------------------------------------------------------------------------
        */

        return [
            'organization_id' => $organizationId,

            'period' => [
                'month' => $start->format('Y-m'),
                'date_from' => $start->toDateString(),
                'date_to' => $end->subDay()->toDateString(),
                'previous_date_from' =>
                    $previousStart->toDateString(),
                'previous_date_to' =>
                    $previousEnd->subDay()->toDateString(),
            ],

            'capabilities' => [
                'inspections' => $canViewInspections,
                'incidents' => $canViewIncidents,
                'corrective_actions' => $canViewActions,
                'create_inspection' => $gate->allows(
                    'create',
                    Inspection::class
                ),
            ],

            'summary' => (object) $summary,

            'charts' => (object) $charts,

            'recent_inspections' => $recentInspections,

            'pending_corrective_actions' =>
                $pendingCorrectiveActions,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Consultas autorizadas
    |--------------------------------------------------------------------------
    */

    private function inspectionQuery(
        User $user,
        string $organizationId
    ): Builder {
        $query = Inspection::query()
            ->where('organization_id', $organizationId);

        if (! $user->hasPermission(
            Permission::InspectionsManage
        )) {
            $query->where('user_id', $user->id);
        }

        return $this->restrictLocations(
            $query,
            $user,
            $organizationId
        );
    }

    private function incidentQuery(
        User $user,
        string $organizationId
    ): Builder {
        $query = Incident::query()
            ->where('organization_id', $organizationId);

        return $this->restrictLocations(
            $query,
            $user,
            $organizationId
        );
    }

    private function correctiveActionQuery(
        User $user,
        string $organizationId
    ): Builder {
        return CorrectiveAction::query()
            ->where('organization_id', $organizationId)
            ->whereHas(
                'incident',
                function (Builder $query) use (
                    $user,
                    $organizationId
                ): void {
                    $query->where(
                        'organization_id',
                        $organizationId
                    );

                    $this->restrictLocations(
                        $query,
                        $user,
                        $organizationId
                    );
                }
            );
    }

    /**
     * Limita las consultas a plantas y áreas asignadas.
     */
    private function restrictLocations(
        Builder $query,
        User $user,
        string $organizationId
    ): Builder {
        return $query->where(
            function (Builder $location) use (
                $user,
                $organizationId
            ): void {
                $location
                    ->whereIn(
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
                                ->where(
                                    'user_id',
                                    $user->id
                                );
                        }
                    )
                    ->orWhereIn(
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
                                ->where(
                                    'user_id',
                                    $user->id
                                );
                        }
                    );
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Indicadores de inspecciones
    |--------------------------------------------------------------------------
    */

    private function countByPeriod(
        Builder $query,
        string $column,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): int {
        return (clone $query)
            ->where($column, '>=', $start)
            ->where($column, '<', $end)
            ->count();
    }

    private function approvalStatistics(
        Builder $query,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $approved = (clone $query)
            ->where('status', 'confirmed')
            ->where(
                'supervisor_confirmed_at',
                '>=',
                $start
            )
            ->where(
                'supervisor_confirmed_at',
                '<',
                $end
            )
            ->count();

        $rejected = (clone $query)
            ->where('status', 'rejected')
            ->where('rejected_at', '>=', $start)
            ->where('rejected_at', '<', $end)
            ->count();

        $reviewed = $approved + $rejected;

        return [
            'approved' => $approved,
            'rejected' => $rejected,
            'reviewed' => $reviewed,
            'rate' => $reviewed > 0
                ? round(($approved / $reviewed) * 100, 2)
                : null,
        ];
    }

    private function approvalTrend(
        Builder $query,
        CarbonImmutable $period
    ): array {
        $trend = [];

        for ($offset = 4; $offset >= 0; $offset--) {
            $start = $period->subMonths($offset)
                ->startOfMonth();

            $end = $start->addMonth();

            $statistics = $this->approvalStatistics(
                $query,
                $start,
                $end
            );

            $trend[] = [
                'month' => $start->format('Y-m'),
                'value' => $statistics['rate'],
                'approved' => $statistics['approved'],
                'rejected' => $statistics['rejected'],
                'reviewed' => $statistics['reviewed'],
            ];
        }

        return $trend;
    }

    /*
    |--------------------------------------------------------------------------
    | Indicadores de incidencias
    |--------------------------------------------------------------------------
    */

    private function incidentsByRisk(
        Builder $query,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $counts = (clone $query)
            ->where('reported_at', '>=', $start)
            ->where('reported_at', '<', $end)
            ->select('risk_level')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('risk_level')
            ->pluck('total', 'risk_level');

        return array_map(
            static fn (string $risk): array => [
                'risk_level' => $risk,
                'value' => (int) $counts->get($risk, 0),
            ],
            self::RISK_LEVELS
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Inspecciones recientes
    |--------------------------------------------------------------------------
    */

    private function recentInspections(
        Builder $query,
        string $organizationId,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $inspections = (clone $query)
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->with([
                'checklistVersion' => fn ($relation) =>
                    $relation->where(
                        'organization_id',
                        $organizationId
                    ),

                'checklistVersion.checklist' =>
                    fn ($relation) =>
                        $relation->where(
                            'organization_id',
                            $organizationId
                        ),

                'plant' => fn ($relation) =>
                    $relation->where(
                        'organization_id',
                        $organizationId
                    ),

                'area' => fn ($relation) =>
                    $relation->where(
                        'organization_id',
                        $organizationId
                    ),

                'productionLine' => fn ($relation) =>
                    $relation->where(
                        'organization_id',
                        $organizationId
                    ),
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        return $inspections
            ->map(function (Inspection $inspection) use (
                $organizationId
            ): array {
                $progress = $this->inspectionProgress(
                    $inspection,
                    $organizationId
                );

                return [
                    'id' => $inspection->id,

                    'checklist_version_id' =>
                        $inspection->checklist_version_id,

                    'checklist_name' =>
                        $inspection->checklistVersion
                            ?->checklist
                            ?->name,

                    'checklist_version' =>
                        $inspection->checklistVersion
                            ?->version,

                    'plant_name' =>
                        $inspection->plant?->name,

                    'area_name' =>
                        $inspection->area?->name,

                    'production_line_name' =>
                        $inspection->productionLine?->name,

                    'started_at' =>
                        $inspection->started_at
                            ?->toISOString(),

                    'status' => $inspection->status,

                    'progress' => $progress['percentage'],

                    'answered_questions' =>
                        $progress['answered'],

                    'required_questions' =>
                        $progress['required'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Calcula el avance de captura de preguntas obligatorias.
     */
    private function inspectionProgress(
        Inspection $inspection,
        string $organizationId
    ): array {
        $questions = DB::table('questions as q')
            ->join(
                'checklist_sections as s',
                's.id',
                '=',
                'q.checklist_section_id'
            )
            ->where(
                'q.organization_id',
                $organizationId
            )
            ->where(
                's.organization_id',
                $organizationId
            )
            ->where(
                's.checklist_version_id',
                $inspection->checklist_version_id
            )
            ->where('q.status', true)
            ->where('q.required', true);

        $required = (clone $questions)
            ->count('q.id');

        $answered = (clone $questions)
            ->join(
                'inspection_answers as a',
                function ($join) use (
                    $inspection,
                    $organizationId
                ): void {
                    $join
                        ->on(
                            'a.question_id',
                            '=',
                            'q.id'
                        )
                        ->on(
                            'a.checklist_section_id',
                            '=',
                            's.id'
                        )
                        ->where(
                            'a.organization_id',
                            $organizationId
                        )
                        ->where(
                            'a.inspection_id',
                            $inspection->id
                        )
                        ->where(
                            'a.checklist_version_id',
                            $inspection->checklist_version_id
                        );
                }
            )
            ->whereNotNull('a.answer')
            ->where('a.answer', '!=', '')
            ->distinct()
            ->count('q.id');

        $percentage = $required > 0
            ? round(($answered / $required) * 100, 2)
            : null;

        return [
            'required' => $required,
            'answered' => $answered,
            'percentage' => $percentage,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Acciones correctivas pendientes
    |--------------------------------------------------------------------------
    */

    private function pendingCorrectiveActions(
        Builder $query,
        string $organizationId,
        CarbonImmutable $today
    ): array {
        $actions = (clone $query)
            ->with([
                'incident' => fn ($relation) =>
                    $relation->where(
                        'organization_id',
                        $organizationId
                    ),

                'incident.plant' => fn ($relation) =>
                    $relation->where(
                        'organization_id',
                        $organizationId
                    ),

                'incident.area' => fn ($relation) =>
                    $relation->where(
                        'organization_id',
                        $organizationId
                    ),

                'incident.productionLine' =>
                    fn ($relation) =>
                        $relation->where(
                            'organization_id',
                            $organizationId
                        ),
            ])

            // Primero las acciones con fecha de vencimiento.
            ->orderByRaw(
                'CASE WHEN due_date IS NULL THEN 1 ELSE 0 END'
            )

            // Las vencidas y próximas a vencer aparecen primero.
            ->orderBy('due_date')

            // Prioridad como segundo criterio.
            ->orderByRaw("
                CASE priority
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'low' THEN 4
                    ELSE 5
                END
            ")

            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(3)
            ->get();

        return $actions
            ->map(function (CorrectiveAction $action) use (
                $today
            ): array {
                $dueDate = $action->due_date;

                return [
                    'id' => $action->id,

                    'incident_id' => $action->incident_id,

                    'description' => $action->description,

                    'priority' => $action->priority,

                    'status' => $action->status,

                    'due_date' => $dueDate?->toDateString(),

                    'overdue' => $dueDate !== null
                        && $dueDate->toDateString()
                            < $today->toDateString(),

                    'plant_name' =>
                        $action->incident?->plant?->name,

                    'area_name' =>
                        $action->incident?->area?->name,

                    'production_line_name' =>
                        $action->incident
                            ?->productionLine
                            ?->name,
                ];
            })
            ->values()
            ->all();
    }
}