<?php

namespace App\Http\Controllers;

use App\Models\CorrectiveAction;
use App\Models\CorrectiveActionUpdate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * GET /api/corrective-actions/{actionId}/updates
     * Lista el historial en orden cronológico ascendente.
     */
    public function index(Request $request,string $actionId): JsonResponse 
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $action = CorrectiveAction::findOrFail($actionId);

        $updates = $action->updates()
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'data' => $updates->items(),
            'meta' => [
                'current_page' => $updates->currentPage(),
                'last_page' => $updates->lastPage(),
                'per_page' => $updates->perPage(),
                'total' => $updates->total(),
            ],
        ]);
    }

    /**
     * POST /api/corrective-actions/{actionId}/updates
     * Registra un avance y actualiza el estado.
     */
    public function store(Request $request,string $actionId,TenantContext $tenantContext): JsonResponse 
    {
        $organizationId = $tenantContext->requireId();

        $validated = $request->validate([
            'organization_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::in([$organizationId]),
            ],
            'status' => [
                'required',
                Rule::in([
                    'in_progress',
                    'completed',
                    'verified',
                ]),
            ],
            'comment' => ['sometimes','nullable','string','max:65535',],
        ]);

        $update = DB::transaction(function () use (
            $actionId,
            $validated,
            $request,
            $organizationId
        ) {
            $action = CorrectiveAction::query()
                ->lockForUpdate()
                ->findOrFail($actionId);

            $newStatus = $validated['status'];

            $this->validateStatusTransition(
                $action->status,
                $newStatus
            );

            $now = now();

            $actionData = [
                'status' => $newStatus,
            ];

            if ($newStatus === 'completed') {
                $actionData['completed_at'] = $now;
            }

            $action->update($actionData);

            return CorrectiveActionUpdate::create([
                // UUID v7: identificador ordenable temporalmente.
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'corrective_action_id' => $action->id,
                'user_id' => $request->user()->id,
                'status' => $newStatus,
                'comment' => $validated['comment'] ?? null,
            ]);
        });

        return response()->json([
            'data' => $update,
        ], 201);
    }

    /**
     * Valida que la acción avance de manera secuencial.
     */
    private function validateStatusTransition(string $currentStatus,string $newStatus): void 
    {
        $currentPosition = array_search(
            $currentStatus,
            self::STATUSES,
            true
        );

        $newPosition = array_search(
            $newStatus,
            self::STATUSES,
            true
        );

        if (
            $currentPosition === false ||
            $newPosition === false ||
            $newPosition !== $currentPosition + 1
        ) {
            throw ValidationException::withMessages([
                'status' =>
                    'Transición de estado no permitida. ' .
                    "Estado actual: {$currentStatus}. " .
                    "Estado solicitado: {$newStatus}.",
            ]);
        }
    }
}