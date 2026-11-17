<?php

namespace App\Http\Controllers;

use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\OrganizationMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * GET /api/corrective-actions
     * Lista las acciones de la organización activa.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'assigned_to' => ['sometimes', 'uuid'],
            'incident_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
        ]);

        $query = CorrectiveAction::query();

        foreach (['assigned_to', 'incident_id', 'status'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        $actions = $query
            ->orderBy('due_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'data' => $actions->items(),
            'meta' => [
                'current_page' => $actions->currentPage(),
                'last_page' => $actions->lastPage(),
                'per_page' => $actions->perPage(),
                'total' => $actions->total(),
            ],
        ]);
    }

    /**
     * POST /api/corrective-actions
     * Crea una acción correctiva vinculada a un incidente.
     */
    public function store(Request $request,TenantContext $tenantContext): JsonResponse 
    {
        $organizationId = $tenantContext->requireId();

        $validated = $request->validate([
            'organization_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::in([$organizationId]),
            ],
            'incident_id' => ['required', 'uuid'],
            'assigned_to' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:65535'],
            'priority' => [
                'sometimes',
                Rule::in(self::PRIORITIES),
            ],
            'due_date' => ['sometimes','nullable','date_format:Y-m-d',],
            'observations' => ['sometimes','nullable','string','max:65535',],
        ]);

        $this->validateIncident($validated['incident_id']);

        $this->validateAssignee(
            $validated['assigned_to'],
            $organizationId
        );

        $action = CorrectiveAction::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'incident_id' => $validated['incident_id'],
            'assigned_to' => $validated['assigned_to'],
            'description' => $validated['description'],
            'priority' => $validated['priority'] ?? 'medium',
            'due_date' => $validated['due_date'] ?? null,
            'status' => 'pending',
            'observations' => $validated['observations'] ?? null,
            'completed_at' => null,
        ]);

        return response()->json([
            'data' => $action,
        ], 201);
    }

    /**
     * GET /api/corrective-actions/{actionId}
     * Devuelve el detalle y el historial de avances.
     */
    public function show(string $actionId): JsonResponse
    {
        $action = CorrectiveAction::query()
            ->with([
                'updates' => function ($query) {
                    $query->orderBy('created_at')
                        ->orderBy('id');
                },
            ])
            ->findOrFail($actionId);

        return response()->json([
            'data' => $action,
        ]);
    }

    /**
     * PATCH /api/corrective-actions/{actionId}
     * Modifica los datos administrativos de la tarea.
     */
    public function update(Request $request,string $actionId,TenantContext $tenantContext): JsonResponse 
    {
        $action = CorrectiveAction::findOrFail($actionId);

        $organizationId = $tenantContext->requireId();

        $validated = $request->validate([
            'organization_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::in([$organizationId]),
            ],
            'incident_id' => ['sometimes', 'required', 'uuid'],
            'assigned_to' => ['sometimes', 'required', 'uuid'],
            'description' => ['sometimes','required','string','max:65535',],
            'priority' => [
                'sometimes',
                Rule::in(self::PRIORITIES),
            ],
            'due_date' => ['sometimes','nullable','date_format:Y-m-d',],
            'observations' => ['sometimes','nullable','string','max:65535',],
        ]);

        if (isset($validated['incident_id'])) {
            $this->validateIncident($validated['incident_id']);
        }

        if (isset($validated['assigned_to'])) {
            $this->validateAssignee(
                $validated['assigned_to'],
                $organizationId
            );
        }

        // organization_id no se modifica desde el cliente.
        unset($validated['organization_id']);

        $action->update($validated);

        return response()->json([
            'data' => $action->refresh(),
        ]);
    }

    /**
     * Verifica que el incidente exista dentro
     * de la organización activa.
     */
    private function validateIncident(string $incidentId): void
    {
        if (! Incident::query()->whereKey($incidentId)->exists()) {
            throw ValidationException::withMessages([
                'incident_id' =>
                    'El incidente no existe en la organización activa.',
            ]);
        }
    }

    /**
     * Verifica que el responsable tenga una membresía
     * activa dentro de la organización.
     */
    private function validateAssignee(string $userId,string $organizationId): void 
    {
        if (
            OrganizationMembership::findActive(
                $userId,
                $organizationId
            ) === null
        ) {
            throw ValidationException::withMessages([
                'assigned_to' =>
                    'El usuario no pertenece o no está activo en la organización.',
            ]);
        }
    }
}