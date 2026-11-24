<?php

namespace App\Http\Controllers;

use App\Models\CorrectiveAction;
use App\Models\CorrectiveActionUpdate;
use App\Models\Organization;
use App\Models\Incident;
use App\Services\IncidentStatusService;
use App\Policies\CorrectiveActionPolicy;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CorrectiveActionUpdateController extends TenantController
{
    private const STATUSES = [
        'pending',
        'in_progress',
        'completed',
        'verified',
    ];

    private const TRANSITIONS = [
        'pending' => 'in_progress',
        'in_progress' => 'completed',
        'completed' => 'verified',
    ];

    public function index(
        Request $request,
        string $actionId
    ): JsonResponse {
        Gate::authorize('viewAny', CorrectiveAction::class);

        $this->rejectUnknownFields(
            $request->query(),
            ['page', 'per_page']
        );

        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $org = app(TenantContext::class)->requireId();

        $action = CorrectiveAction::query()
            ->where('organization_id', $org)
            ->findOrFail($actionId);

        abort_unless(
            app(CorrectiveActionPolicy::class)
                ->view($request->user(), $action),
            404
        );

        $page = CorrectiveActionUpdate::query()
            ->where('organization_id', $org)
            ->where('corrective_action_id', $action->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(
                $data['per_page'] ?? 15,
                ['*'],
                'page',
                $data['page'] ?? 1
            );

        return response()->json([
            'data' => array_map(
                $this->dto(...),
                $page->items()
            ),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(
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

        abort_unless(
            $policy->view($user, $candidate),
            404
        );

        $this->requireJsonObject(
            $request,
            ['status', 'comment']
        );

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'in_progress',
                    'completed',
                    'verified',
                ]),
            ],
            'comment' => [
                'sometimes',
                'nullable',
                'string',
                'max:65535',
            ],
        ]);

        $isVerification = $data['status'] === 'verified';

        if (!$isVerification) {
            Gate::authorize('recordUpdate', $candidate);
        }

        $update = DB::transaction(function () use ($org, $user, $policy, $actionId, $data, $isVerification): CorrectiveActionUpdate {
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

            abort_unless(
                $policy->view($user, $action),
                404
            );

            $before = $action->status;
            $after = $data['status'];

            if ($isVerification) {
                $this->validateStatusTransition(
                    $before,
                    $after
                );

                Gate::authorize('verify', $action);
            } else {
                Gate::authorize('recordUpdate', $action);

                $this->validateStatusTransition(
                    $before,
                    $after
                );
            }

            $incident = Incident::query()
                ->where('organization_id', $org)
                ->lockForUpdate()
                ->findOrFail($action->incident_id);

            if ($incident->status === 'closed') {
                throw ValidationException::withMessages([
                    'status' => 'No se pueden registrar avances en acciones de una incidencia cerrada.',
                ]);
            }

            $changes = [
                'status' => $after,
            ];

            if ($after === 'completed') {
                $changes['completed_at'] = now();
            }

            $oldValues = [
                'status' => $before,
                'completed_at' => $action->completed_at?->toISOString(),
            ];

            $action->update($changes);

            $update = CorrectiveActionUpdate::create([
                'id' => (string) Str::uuid7(),
                'organization_id' => $org,
                'corrective_action_id' => $action->id,
                'user_id' => $user->id,
                'status' => $after,
                'comment' => $data['comment'] ?? null,
            ]);

            $auditValues = [
                'status' => $after,
                'completed_at' => $action->completed_at?->toISOString(),
                'update_id' => $update->id,
            ];

            if ($isVerification) {
                $completion = CorrectiveActionUpdate::query()
                    ->where('organization_id', $org)
                    ->where('corrective_action_id', $action->id)
                    ->where('status', 'completed')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();

                $auditValues['completed_by'] = $completion?->user_id;
                $auditValues['verified_by'] = $user->id;
            }

            app(AuditLogger::class)->record(
                'corrective_action.status_changed',
                $action,
                $oldValues,
                $auditValues
            );

            // Sincronizar el estado de la incidencia.
            app(IncidentStatusService::class)->synchronize(
                $incident,
                $action->id
            );

            return $update;
        });

        return response()->json([
            'data' => $this->dto($update),
        ], 201);
    }

    private function validateStatusTransition(
        string $current,
        string $requested
    ): void {
        if (
            !in_array($current, self::STATUSES, true)
            || (self::TRANSITIONS[$current] ?? null) !== $requested
        ) {
            throw ValidationException::withMessages([
                'status' => "Transición no permitida: {$current} → {$requested}.",
            ]);
        }
    }

    private function requireJsonObject(
        Request $request,
        array $allowedFields
    ): void {
        if (
            $request->query() !== []
            || !$request->isJson()
            || !is_object(json_decode($request->getContent()))
        ) {
            throw ValidationException::withMessages([
                'fields' => 'Se requiere un objeto JSON sin parámetros de consulta.',
            ]);
        }

        $this->rejectUnknownFields(
            $request->all(),
            $allowedFields
        );
    }

    private function rejectUnknownFields(
        array $data,
        array $allowedFields
    ): void {
        if (
            array_diff(
                array_keys($data),
                $allowedFields
            ) !== []
        ) {
            throw ValidationException::withMessages([
                'fields' => 'La solicitud contiene campos no permitidos.',
            ]);
        }
    }

    private function dto(
        CorrectiveActionUpdate $update
    ): array {
        return [
            'id' => $update->id,
            'organization_id' => $update->organization_id,
            'corrective_action_id' => $update->corrective_action_id,
            'user_id' => $update->user_id,
            'user_name' => DB::table('users')
                ->where('id', $update->user_id)
                ->value('name'),
            'status' => $update->status,
            'comment' => $update->comment,
            'created_at' => $update->created_at,
        ];
    }
}
