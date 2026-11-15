<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Incident;
use App\Models\Inspection;
use App\Models\ProductionLine;
use App\Models\Question;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IncidentController extends TenantController
{
    private const TYPES = [
        'unsafe_condition',
        'unsafe_act',
        'near_miss',
        'environmental_spill',
        'equipment_damage',
    ];

    private const RISK_LEVELS = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    private const STATUSES = [
        'open',
        'in_progress',
        'resolved',
        'closed',
    ];

    /**
     * GET /api/incidents
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'risk_level' => ['sometimes', Rule::in(self::RISK_LEVELS)],
            'plant_id' => ['sometimes', 'uuid'],
            'area_id' => ['sometimes', 'uuid'],
            'reported_by' => ['sometimes', 'uuid'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => [
                'sometimes',
                'date_format:Y-m-d',
                'after_or_equal:date_from',
            ],
        ]);

        $query = Incident::query();

        foreach ([
            'status',
            'risk_level',
            'plant_id',
            'area_id',
            'reported_by',
        ] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        if (isset($validated['date_from'])) {
            $query->whereDate(
                'reported_at',
                '>=',
                $validated['date_from']
            );
        }

        if (isset($validated['date_to'])) {
            $query->whereDate(
                'reported_at',
                '<=',
                $validated['date_to']
            );
        }

        $incidents = $query
            ->orderByDesc('reported_at')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'data' => $incidents->items(),
            'meta' => [
                'current_page' => $incidents->currentPage(),
                'last_page' => $incidents->lastPage(),
                'per_page' => $incidents->perPage(),
                'total' => $incidents->total(),
            ],
        ]);
    }

    /**
     * POST /api/incidents
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
            'inspection_id' => ['sometimes', 'nullable', 'uuid'],
            'question_id' => ['sometimes', 'nullable', 'uuid'],
            'plant_id' => ['required', 'uuid'],
            'area_id' => ['required', 'uuid'],
            'production_line_id' => ['sometimes', 'nullable', 'uuid'],
            'type' => [
                'sometimes',
                'nullable',
                Rule::in(self::TYPES),
            ],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:65535'],
            'risk_level' => ['required', Rule::in(self::RISK_LEVELS)],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'location_description' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $this->validateLocationAndReferences($validated);

        $incident = Incident::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'inspection_id' => $validated['inspection_id'] ?? null,
            'question_id' => $validated['question_id'] ?? null,
            'reported_by' => $request->user()->id,
            'plant_id' => $validated['plant_id'],
            'area_id' => $validated['area_id'],
            'production_line_id' => $validated['production_line_id'] ?? null,
            'type' => $validated['type'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'risk_level' => $validated['risk_level'],
            'status' => 'open',
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'location_description' => $validated['location_description'] ?? null,
            'reported_at' => now(),
        ]);

        return response()->json([
            'data' => $incident,
        ], 201);
    }

    /**
     * GET /api/incidents/{incidentId}
     */
    public function show(string $incidentId): JsonResponse
    {
        $incident = Incident::findOrFail($incidentId);

        return response()->json([
            'data' => $incident,
        ]);
    }

    /**
     * PATCH /api/incidents/{incidentId}/status
     */
    public function updateStatus(Request $request,string $incidentId): JsonResponse 
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $incident = DB::transaction(function () use (
            $incidentId,
            $validated
        ) {
            $incident = Incident::query()
                ->lockForUpdate()
                ->findOrFail($incidentId);

            $incident->update([
                'status' => $validated['status'],
            ]);

            return $incident->refresh();
        });

        return response()->json([
            'data' => $incident,
        ]);
    }

    /**
     * Valida coherencia de ubicación e inspección.
     */
    private function validateLocationAndReferences(array $data): void
    {
        $area = Area::query()
            ->whereKey($data['area_id'])
            ->where('plant_id', $data['plant_id'])
            ->first();

        if ($area === null) {
            throw ValidationException::withMessages([
                'area_id' =>
                    'El área no pertenece a la planta indicada.',
            ]);
        }

        if (! empty($data['production_line_id'])) {
            $line = ProductionLine::query()
                ->whereKey($data['production_line_id'])
                ->where('area_id', $area->id)
                ->first();

            if ($line === null) {
                throw ValidationException::withMessages([
                    'production_line_id' =>
                        'La línea no pertenece al área indicada.',
                ]);
            }
        }

        if (! empty($data['question_id']) &&
            empty($data['inspection_id'])) {
            throw ValidationException::withMessages([
                'inspection_id' =>
                    'Se requiere una inspección al indicar una pregunta.',
            ]);
        }

        if (! empty($data['inspection_id'])) {
            $inspection = Inspection::find($data['inspection_id']);

            if ($inspection === null) {
                throw ValidationException::withMessages([
                    'inspection_id' => 'La inspección no existe.',
                ]);
            }

            if (
                $inspection->plant_id !== $data['plant_id'] ||
                $inspection->area_id !== $data['area_id'] ||
                $inspection->production_line_id !==
                    ($data['production_line_id'] ?? null)
            ) {
                throw ValidationException::withMessages([
                    'inspection_id' =>
                        'La ubicación no coincide con la inspección.',
                ]);
            }

            if (! empty($data['question_id'])) {
                $questionExists = Question::query()
                    ->whereKey($data['question_id'])
                    ->whereHas('checklistSection', function ($query) use ($inspection) {
                        $query->where(
                            'checklist_version_id',
                            $inspection->checklist_version_id
                        );
                    })
                    ->exists();

                if (! $questionExists) {
                    throw ValidationException::withMessages([
                        'question_id' =>
                            'La pregunta no pertenece al checklist inspeccionado.',
                    ]);
                }
            }
        }
    }
}