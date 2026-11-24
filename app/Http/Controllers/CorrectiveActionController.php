<?php

namespace App\Http\Controllers;

use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Policies\CorrectiveActionPolicy;
use App\Policies\InspectionPolicy;
use App\Services\IncidentStatusService;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CorrectiveActionController extends TenantController
{
    private const PRIORITIES = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    private const STATUSES = [
        'pending',
        'in_progress',
        'completed',
        'verified',
    ];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CorrectiveAction::class);

        $this->rejectUnknownFields($request->query(), [
            'page',
            'per_page',
            'assigned_to',
            'incident_id',
            'status',
        ]);

        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'assigned_to' => ['sometimes', 'uuid'],
            'incident_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
        ]);

        $org = app(TenantContext::class)->requireId();
        $user = $request->user();

        $query = CorrectiveAction::query()
            ->where('organization_id', $org)
            ->whereHas('incident', function ($query) use ($org, $user) {
                $query->where('organization_id', $org)
                    ->where(function ($location) use ($org, $user) {
                        $location->whereIn('plant_id', function ($sub) use ($org, $user) {
                            $sub->select('plant_id')
                                ->from('user_plants')
                                ->where('organization_id', $org)
                                ->where('user_id', $user->id);
                        })->orWhereIn('area_id', function ($sub) use ($org, $user) {
                            $sub->select('area_id')
                                ->from('user_areas')
                                ->where('organization_id', $org)
                                ->where('user_id', $user->id);
                        });
                    });
            });

        foreach (['assigned_to', 'incident_id', 'status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        $page = $query
            ->orderBy('due_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(
                $data['per_page'] ?? 15,
                ['*'],
                'page',
                $data['page'] ?? 1
            );

        return response()->json([
            'data' => array_map($this->dto(...), $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', CorrectiveAction::class);

        $this->requireJsonObject($request, [
            'incident_id',
            'assigned_to',
            'description',
            'priority',
            'due_date',
            'observations',
        ]);

        $data = $request->validate([
            'incident_id' => ['required', 'uuid'],
            'assigned_to' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:65535'],
            'priority' => ['sometimes', Rule::in(self::PRIORITIES)],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'observations' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ]);

        $org = app(TenantContext::class)->requireId();
        $user = $request->user();

        $action = DB::transaction(function () use ($data, $org, $user) {
            Organization::query()
                ->whereKey($org)
                ->lockForUpdate()
                ->firstOrFail();

            app(TenantContext::class)
                ->flushPermissionCache($user->id, $org);

            Gate::authorize('create', CorrectiveAction::class);

            $incident = Incident::query()
                ->where('organization_id', $org)
                ->lockForUpdate()
                ->findOrFail($data['incident_id']);

            abort_unless(
                app(InspectionPolicy::class)->hasLocationAccess(
                    $user,
                    $incident->plant_id,
                    $incident->area_id
                ),
                404
            );

            app(IncidentStatusService::class)
                ->validateActionCreation($incident);

            $this->validateAssignee($data['assigned_to'], $org);

            $action = CorrectiveAction::create([
                'id' => (string) Str::uuid7(),
                'organization_id' => $org,
                'incident_id' => $incident->id,
                'assigned_to' => $data['assigned_to'],
                'description' => $data['description'],
                'priority' => $data['priority'] ?? 'medium',
                'due_date' => $data['due_date'] ?? null,
                'observations' => $data['observations'] ?? null,
                'status' => 'pending',
                'completed_at' => null,
            ]);

            app(AuditLogger::class)->record(
                'corrective_action.created',
                $action,
                [],
                $this->auditValues($action)
            );

            return $action;
        });

        return response()->json([
            'data' => $this->dto($action),
        ], 201);
    }

    public function show(Request $request, string $actionId): JsonResponse
    {
        Gate::authorize('viewAny', CorrectiveAction::class);

        $org = app(TenantContext::class)->requireId();

        $action = CorrectiveAction::query()
            ->where('organization_id', $org)
            ->findOrFail($actionId);

        abort_unless(
            app(CorrectiveActionPolicy::class)
                ->view($request->user(), $action),
            404
        );

        $action->load([
            'updates' => function ($query) {
                $query->orderBy('created_at')->orderBy('id');
            },
        ]);

        $result = $this->dto($action);

        $userNames = DB::table('users')
            ->whereIn('id', $action->updates->pluck('user_id')->unique())
            ->pluck('name', 'id');

        $result['updates'] = $action->updates
            ->map(fn ($update) => [
                'id' => $update->id,
                'corrective_action_id' => $update->corrective_action_id,
                'user_id' => $update->user_id,
                'user_name' => $userNames->get($update->user_id),
                'status' => $update->status,
                'comment' => $update->comment,
                'created_at' => $update->created_at,
                'updated_at' => $update->updated_at,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $result]);
    }

    public function update(
        Request $request,
        string $actionId
    ): JsonResponse {
        Gate::authorize('viewAny', CorrectiveAction::class);

        $org = app(TenantContext::class)->requireId();
        $user = $request->user();
        $policy = app(CorrectiveActionPolicy::class);

        $candidate = CorrectiveAction::query()
            ->where('organization_id', $org)
            ->findOrFail($actionId);

        abort_unless($policy->view($user, $candidate), 404);

        Gate::authorize('update', $candidate);

        $this->requireJsonObject($request, [
            'incident_id',
            'assigned_to',
            'description',
            'priority',
            'due_date',
            'observations',
        ]);

        $data = $request->validate([
            'incident_id' => ['sometimes', 'required', 'uuid'],
            'assigned_to' => ['sometimes', 'required', 'uuid'],
            'description' => ['sometimes', 'required', 'string', 'max:65535'],
            'priority' => ['sometimes', Rule::in(self::PRIORITIES)],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'observations' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ]);

        if ($data === []) {
            throw ValidationException::withMessages([
                'fields' => 'Debe proporcionar al menos un campo.',
            ]);
        }

        $action = DB::transaction(function () use ($data, $org, $user, $policy, $actionId) {
            Organization::query()
                ->whereKey($org)
                ->lockForUpdate()
                ->firstOrFail();

            app(TenantContext::class)
                ->flushPermissionCache($user->id, $org);

            $action = CorrectiveAction::query()
                ->where('organization_id', $org)
                ->lockForUpdate()
                ->findOrFail($actionId);

            abort_unless($policy->view($user, $action), 404);
            Gate::authorize('update', $action);

            if (
                isset($data['incident_id'])
                && $data['incident_id'] !== $action->incident_id
            ) {
                throw ValidationException::withMessages([
                    'incident_id' => 'No se puede cambiar la incidencia asociada a una acción correctiva existente.',
                ]);
            }

            if (
                isset($data['assigned_to'])
                && $data['assigned_to'] !== $action->assigned_to
                && in_array($action->status, ['completed', 'verified'], true)
            ) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'No se puede reasignar una acción completada o verificada.',
                ]);
            }

            $incidentId = $data['incident_id'] ?? $action->incident_id;

            $incident = Incident::query()
                ->where('organization_id', $org)
                ->lockForUpdate()
                ->findOrFail($incidentId);

            abort_unless(
                app(InspectionPolicy::class)->hasLocationAccess(
                    $user,
                    $incident->plant_id,
                    $incident->area_id
                ),
                404
            );

            if ($incident->status === 'closed') {
                throw ValidationException::withMessages([
                    'incident_id' => 'No se pueden modificar acciones correctivas de una incidencia cerrada.',
                ]);
            }

            if (isset($data['assigned_to'])) {
                $this->validateAssignee($data['assigned_to'], $org);
            }

            $before = $this->auditValues($action);

            $action->fill($data);

            if ($action->isDirty()) {
                $action->save();

                app(AuditLogger::class)->record(
                    'corrective_action.updated',
                    $action,
                    $before,
                    $this->auditValues($action)
                );
            }

            return $action->refresh();
        });

        return response()->json([
            'data' => $this->dto($action),
        ]);
    }

    private function validateAssignee(
        string $userId,
        string $organizationId
    ): void {
        if (
            OrganizationMembership::findActive(
                $userId,
                $organizationId
            ) === null
        ) {
            throw ValidationException::withMessages([
                'assigned_to' => 'El responsable no tiene una membresía activa en la organización.',
            ]);
        }
    }

    private function requireJsonObject(
        Request $request,
        array $allowedFields
    ): void {
        if (
            $request->query() !== []
            || ! $request->isJson()
            || ! is_object(json_decode($request->getContent()))
        ) {
            throw ValidationException::withMessages([
                'fields' => 'Se requiere un objeto JSON sin parámetros de consulta.',
            ]);
        }

        $this->rejectUnknownFields($request->all(), $allowedFields);
    }

    private function rejectUnknownFields(
        array $data,
        array $allowedFields
    ): void {
        if (array_diff(array_keys($data), $allowedFields) !== []) {
            throw ValidationException::withMessages([
                'fields' => 'La solicitud contiene campos no permitidos.',
            ]);
        }
    }

    private function auditValues(CorrectiveAction $action): array
    {
        return [
            'incident_id' => $action->incident_id,
            'assigned_to' => $action->assigned_to,
            'description' => $action->description,
            'priority' => $action->priority,
            'due_date' => $action->due_date?->format('Y-m-d'),
            'observations' => $action->observations,
            'status' => $action->status,
            'completed_at' => $action->completed_at?->toISOString(),
        ];
    }

    private function dto(CorrectiveAction $action): array
    {
        return [
            'id' => $action->id,
            'organization_id' => $action->organization_id,
            'incident_id' => $action->incident_id,
            'assigned_to' => $action->assigned_to,
            'assignee_name' => DB::table('users')
                ->where('id', $action->assigned_to)
                ->value('name'),
            'description' => $action->description,
            'priority' => $action->priority,
            'due_date' => $action->due_date?->format('Y-m-d'),
            'status' => $action->status,
            'observations' => $action->observations,
            'completed_at' => $action->completed_at,
            'created_at' => $action->created_at,
            'updated_at' => $action->updated_at,
        ];
    }
}
