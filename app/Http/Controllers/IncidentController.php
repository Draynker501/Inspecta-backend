<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\Incident;
use App\Models\Inspection;
use App\Models\Organization;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Models\Question;
use App\Policies\IncidentPolicy;
use App\Policies\InspectionPolicy;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IncidentController extends TenantController
{
    private const TYPES = ['unsafe_condition', 'unsafe_act', 'near_miss', 'environmental_spill', 'equipment_damage'];

    private const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    private const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    private const TRANSITIONS = [
        'open' => ['in_progress'],
        'in_progress' => ['resolved'],
        'resolved' => ['closed'],
        'closed' => [],
    ];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Incident::class);
        $this->rejectUnknownFields($request->query(), [
            'page', 'per_page', 'status', 'risk_level', 'plant_id', 'area_id',
            'reported_by', 'date_from', 'date_to',
        ]);
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'risk_level' => ['sometimes', Rule::in(self::RISK_LEVELS)],
            'plant_id' => ['sometimes', 'uuid'],
            'area_id' => ['sometimes', 'uuid'],
            'reported_by' => ['sometimes', 'uuid'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        if (isset($data['date_from'], $data['date_to']) && $data['date_to'] < $data['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'La fecha final no puede ser anterior a la inicial.']);
        }

        $organizationId = app(TenantContext::class)->requireId();
        $user = $request->user();
        $query = Incident::query()->where('organization_id', $organizationId);

        // Incluso un usuario con permiso de lectura solo ve ubicaciones asignadas.
        $query->where(function ($builder) use ($user, $organizationId): void {
            $builder->whereIn('plant_id', function ($subquery) use ($user, $organizationId): void {
                $subquery->select('plant_id')->from('user_plants')
                    ->where('user_id', $user->id)->where('organization_id', $organizationId);
            })->orWhereIn('area_id', function ($subquery) use ($user, $organizationId): void {
                $subquery->select('area_id')->from('user_areas')
                    ->where('user_id', $user->id)->where('organization_id', $organizationId);
            });
        });
        foreach (['status', 'risk_level', 'plant_id', 'area_id', 'reported_by'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['date_from'])) {
            $query->whereDate('reported_at', '>=', $data['date_from']);
        }
        if (isset($data['date_to'])) {
            $query->whereDate('reported_at', '<=', $data['date_to']);
        }
        $page = $query->orderByDesc('reported_at')->orderByDesc('created_at')->orderBy('id')
            ->paginate($data['per_page'] ?? 15, ['*'], 'page', $data['page'] ?? 1);

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
        Gate::authorize('create', Incident::class);
        $this->requireJsonObject($request, [
            'inspection_id', 'question_id', 'plant_id', 'area_id', 'production_line_id',
            'type', 'title', 'description', 'risk_level', 'latitude', 'longitude',
            'location_description',
        ]);
        $coordinate = static function (float $max): \Closure {
            return static function (string $attribute, mixed $value, \Closure $fail) use ($max): void {
                if ((! is_int($value) && ! is_float($value) && ! is_string($value))
                    || ! is_numeric($value) || ! is_finite((float) $value)
                    || abs((float) $value) > $max
                    || (float) $value !== round((float) $value, 7)
                    || (is_string($value) && ! preg_match('/^-?\d+(?:\.\d{1,7})?$/D', $value))) {
                    $fail('La coordenada no es válida.');
                }
            };
        };
        $data = $request->validate([
            'inspection_id' => ['sometimes', 'nullable', 'uuid'],
            'question_id' => ['sometimes', 'nullable', 'uuid'],
            'plant_id' => ['required', 'uuid'],
            'area_id' => ['required', 'uuid'],
            'production_line_id' => ['sometimes', 'nullable', 'uuid'],
            'type' => ['sometimes', 'nullable', Rule::in(self::TYPES)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('La descripción supera 65535 bytes.');
                }
            }],
            'risk_level' => ['required', Rule::in(self::RISK_LEVELS)],
            'latitude' => ['sometimes', 'nullable', $coordinate(90)],
            'longitude' => ['sometimes', 'nullable', $coordinate(180)],
            'location_description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        if (isset($data['latitude']) !== isset($data['longitude'])) {
            throw ValidationException::withMessages(['coordinates' => 'Las coordenadas deben proporcionarse juntas.']);
        }
        if (isset($data['question_id']) && ! isset($data['inspection_id'])) {
            throw ValidationException::withMessages(['inspection_id' => 'La inspección es obligatoria cuando se indica una pregunta.']);
        }

        $org = app(TenantContext::class)->requireId();
        $user = $request->user();
        $policy = app(InspectionPolicy::class);
        abort_unless($policy->hasLocationAccess($user, $data['plant_id'], $data['area_id']), 404);

        $incident = DB::transaction(function () use ($data, $org, $user, $policy): Incident {
            Organization::query()->whereKey($org)->lockForUpdate()->firstOrFail();
            app(TenantContext::class)->flushPermissionCache($user->id, $org);
            Gate::authorize('create', Incident::class);
            abort_unless($policy->hasLocationAccess($user, $data['plant_id'], $data['area_id']), 404);

            $inspection = isset($data['inspection_id'])
                ? Inspection::query()->where('organization_id', $org)
                    ->lockForUpdate()->findOrFail($data['inspection_id'])
                : null;
            $plant = Plant::query()->where('organization_id', $org)
                ->lockForUpdate()->findOrFail($data['plant_id']);
            $area = Area::query()->where('organization_id', $org)
                ->where('plant_id', $plant->id)->lockForUpdate()->findOrFail($data['area_id']);
            $line = isset($data['production_line_id'])
                ? ProductionLine::query()->where('organization_id', $org)
                    ->where('area_id', $area->id)->lockForUpdate()->findOrFail($data['production_line_id'])
                : null;

            if ($inspection !== null && ($inspection->plant_id !== $plant->id
                || $inspection->area_id !== $area->id
                || $inspection->production_line_id !== $line?->id)) {
                throw ValidationException::withMessages(['inspection_id' => 'La ubicación del incidente no coincide con la inspección.']);
            }
            if (isset($data['question_id'])) {
                $exists = Question::query()->where('organization_id', $org)
                    ->whereKey($data['question_id'])
                    ->whereHas('checklistSection', fn ($query) => $query
                        ->where('organization_id', $org)
                        ->where('checklist_version_id', $inspection->checklist_version_id))
                    ->exists();
                if (! $exists) {
                    throw ValidationException::withMessages(['question_id' => 'La pregunta no pertenece a la versión inspeccionada.']);
                }
            }
            app(TenantContext::class)->flushPermissionCache($user->id, $org);
            Gate::authorize('create', Incident::class);
            abort_unless($policy->hasLocationAccess($user, $plant->id, $area->id), 404);
            $incident = Incident::create([
                'id' => (string) Str::uuid7(),
                'organization_id' => $org,
                'inspection_id' => $inspection?->id,
                'question_id' => $data['question_id'] ?? null,
                'reported_by' => $user->id,
                'plant_id' => $plant->id,
                'area_id' => $area->id,
                'production_line_id' => $line?->id,
                'type' => $data['type'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'],
                'risk_level' => $data['risk_level'],
                'status' => 'open',
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'location_description' => $data['location_description'] ?? null,
                'reported_at' => now(),
            ]);
            app(AuditLogger::class)->record('incident.created', $incident, [], $incident->only([
                'inspection_id', 'question_id', 'plant_id', 'area_id', 'production_line_id',
                'type', 'risk_level', 'status',
            ]));

            return $incident;
        });

        return response()->json(['data' => $this->dto($incident)], 201);
    }

    public function show(Request $request, string $incidentId): JsonResponse
    {
        Gate::authorize('viewAny', Incident::class);
        $incident = Incident::query()->where('organization_id', app(TenantContext::class)->requireId())
            ->findOrFail($incidentId);
        abort_unless(app(IncidentPolicy::class)->view($request->user(), $incident), 404);

        return response()->json(['data' => $this->dto($incident)]);
    }

    public function updateStatus(Request $request, string $incidentId): JsonResponse
    {
        Gate::authorize('viewAny', Incident::class);
        $org = app(TenantContext::class)->requireId();
        $user = $request->user();
        $policy = app(IncidentPolicy::class);
        $candidate = Incident::query()->where('organization_id', $org)->findOrFail($incidentId);
        abort_unless($policy->view($user, $candidate), 404);
        Gate::authorize('update', $candidate);
        $this->requireJsonObject($request, ['status']);
        $data = $request->validate(['status' => ['required', Rule::in(self::STATUSES)]]);

        $incident = DB::transaction(function () use ($org, $user, $incidentId, $policy, $data): Incident {
            Organization::query()->whereKey($org)->lockForUpdate()->firstOrFail();
            app(TenantContext::class)->flushPermissionCache($user->id, $org);
            $incident = Incident::query()->where('organization_id', $org)
                ->lockForUpdate()->findOrFail($incidentId);
            abort_unless($policy->view($user, $incident), 404);
            Gate::authorize('update', $incident);

            $before = $incident->status;
            $after = $data['status'];
            if ($before !== $after) {
                if (! in_array($after, self::TRANSITIONS[$before] ?? [], true)) {
                    throw ValidationException::withMessages(['status' => 'La transición de estado no está permitida.']);
                }
                $incident->update(['status' => $after]);
                app(AuditLogger::class)->record('incident.status_changed', $incident,
                    ['status' => $before], ['status' => $after]);
            }

            return $incident->refresh();
        });

        return response()->json(['data' => $this->dto($incident)]);
    }

    private function requireJsonObject(Request $request, array $allowedFields): void
    {
        if ($request->query() !== [] || ! $request->isJson()
            || ! is_object(json_decode($request->getContent()))) {
            throw ValidationException::withMessages(['fields' => 'Se requiere un objeto JSON sin parámetros de consulta.']);
        }
        $this->rejectUnknownFields($request->all(), $allowedFields);
    }

    private function rejectUnknownFields(array $data, array $allowedFields): void
    {
        if (array_diff(array_keys($data), $allowedFields) !== []) {
            throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
        }
    }

    private function dto(Incident $incident): array
    {
        $data = $incident->only([
            'id', 'organization_id', 'inspection_id', 'question_id', 'reported_by',
            'plant_id', 'area_id', 'production_line_id', 'type', 'title', 'description',
            'risk_level', 'status', 'location_description', 'reported_at', 'created_at', 'updated_at',
        ]);
        foreach (['latitude', 'longitude'] as $field) {
            $raw = $incident->getRawOriginal($field);
            $data[$field] = $raw === null ? null : number_format((float) $raw, 7, '.', '');
        }

        return $data;
    }
}