<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Area;
use App\Models\Checklist;
use App\Models\ChecklistVersion;
use App\Models\Inspection;
use App\Models\Organization;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Policies\InspectionPolicy;
use App\Services\InspectionSummaryService;
use App\Support\Audit\AuditLogger;
use App\Support\Inspections\InspectionAnswerValidator;
use App\Support\Inspections\InspectionCompletion;
use App\Support\Inspections\InspectionReview;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InspectionController extends TenantController
{
    public function __construct(
        private readonly InspectionSummaryService $inspectionSummaryService
    ) {}

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Inspection::class);
        if (
            ! $request->isJson() || ! is_object(json_decode($request->getContent()))
            || array_diff(array_keys($request->all()), [
                'checklist_version_id',
                'plant_id',
                'area_id',
                'production_line_id',
                'latitude',
                'longitude',
                'location_description',
                'observations',
            ]) !== []
        ) {
            throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos o no es un objeto JSON.']);
        }
        $coordinate = static function (float $max): \Closure {
            return static function (string $attribute, mixed $value, \Closure $fail) use ($max): void {
                if (
                    (! is_int($value) && ! is_float($value) && ! is_string($value))
                    || ! is_numeric($value) || ! is_finite((float) $value)
                    || abs((float) $value) > $max
                    || (float) $value !== round((float) $value, 7)
                    || (is_string($value) && ! preg_match('/^-?\d+(?:\.\d{1,7})?$/D', $value))
                ) {
                    $fail('La coordenada no es válida.');
                }
            };
        };
        $data = $request->validate([
            'checklist_version_id' => ['required', 'uuid'],
            'plant_id' => ['required', 'uuid'],
            'area_id' => ['required', 'uuid'],
            'production_line_id' => ['sometimes', 'nullable', 'uuid'],
            'latitude' => ['sometimes', 'nullable', $coordinate(90)],
            'longitude' => ['sometimes', 'nullable', $coordinate(180)],
            'location_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'observations' => [
                'sometimes',
                'nullable',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 65535) {
                        $fail('Las observaciones superan 65535 bytes.');
                    }
                },
            ],
        ]);
        if (isset($data['latitude']) !== isset($data['longitude'])) {
            throw ValidationException::withMessages(['coordinates' => 'Las coordenadas deben proporcionarse juntas.']);
        }
        $user = $request->user();
        $context = app(TenantContext::class);
        $org = $context->requireId();
        $policy = app(InspectionPolicy::class);
        abort_unless($policy->hasLocationAccess($user, $data['plant_id'], $data['area_id']), 404);

        $inspection = DB::transaction(function () use ($data, $user, $context, $org, $policy): Inspection {
            Organization::query()->whereKey($org)->lockForUpdate()->firstOrFail();
            $context->flushPermissionCache($user->id, $org);
            Gate::authorize('create', Inspection::class);
            abort_unless($policy->hasLocationAccess($user, $data['plant_id'], $data['area_id']), 404);

            $candidate = ChecklistVersion::query()->where('organization_id', $org)->findOrFail($data['checklist_version_id']);
            $checklist = Checklist::query()->where('organization_id', $org)->lockForUpdate()->findOrFail($candidate->checklist_id);
            $version = ChecklistVersion::query()->where('organization_id', $org)->where('checklist_id', $checklist->id)
                ->lockForUpdate()->findOrFail($candidate->id);
            $plant = Plant::query()->where('organization_id', $org)->lockForUpdate()->findOrFail($data['plant_id']);
            $area = Area::query()->where('organization_id', $org)->where('plant_id', $plant->id)
                ->lockForUpdate()->findOrFail($data['area_id']);
            $line = isset($data['production_line_id'])
                ? ProductionLine::query()->where('organization_id', $org)->where('area_id', $area->id)
                    ->lockForUpdate()->findOrFail($data['production_line_id'])
                : null;
            $context->flushPermissionCache($user->id, $org);
            Gate::authorize('create', Inspection::class);
            abort_unless($policy->hasLocationAccess($user, $plant->id, $area->id), 404);
            $hasQuestion = DB::table('questions as q')
                ->join('checklist_sections as s', 's.id', '=', 'q.checklist_section_id')
                ->where('s.organization_id', $org)->where('q.organization_id', $org)
                ->where('s.checklist_version_id', $version->id)->where('q.status', true)->exists();
            if (
                ! $checklist->status || $version->status !== 'published' || ! $hasQuestion
                || ! $plant->status || ! $area->status || ($line !== null && ! $line->status)
            ) {
                throw ValidationException::withMessages(['resources' => 'Los recursos no están disponibles para una nueva inspección.']);
            }
            $inspection = Inspection::create([
                'id' => (string) Str::uuid7(),
                'organization_id' => $org,
                'user_id' => $user->id,
                'supervisor_id' => null,
                'checklist_version_id' => $version->id,
                'plant_id' => $plant->id,
                'area_id' => $area->id,
                'production_line_id' => $line?->id,
                'status' => 'draft',
                'started_at' => now(),
                'completed_at' => null,
                'supervisor_confirmed_at' => null,
                'rejected_at' => null,
                'supervisor_observations' => null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'location_description' => $data['location_description'] ?? null,
                'observations' => $data['observations'] ?? null,
            ]);
            app(AuditLogger::class)->record('inspection.created', $inspection, [], $inspection->only([
                'checklist_version_id',
                'plant_id',
                'area_id',
                'production_line_id',
                'status',
            ]));

            return $inspection;
        });

        return response()->json(['data' => $this->dto($inspection)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Inspection::class);

        if (
            array_diff(
                array_keys($request->query()),
                ['page', 'per_page', 'status', 'plant_id', 'area_id']
            ) !== []
        ) {
            throw ValidationException::withMessages([
                'fields' => 'La solicitud contiene campos no permitidos.',
            ]);
        }

        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => [
                'sometimes',
                Rule::in([
                    'draft',
                    'completed',
                    'confirmed',
                    'rejected',
                ]),
            ],
            'plant_id' => ['sometimes', 'uuid'],
            'area_id' => ['sometimes', 'uuid'],
        ]);

        $user = $request->user();

        $org = app(TenantContext::class)->requireId();

        $query = Inspection::query()
            ->where('organization_id', $org);

        if (! $user->hasPermission(Permission::InspectionsManage)) {
            $query->where('user_id', $user->id);
        }

        $query->where(function ($query) use ($org, $user): void {
            $query->whereIn(
                'plant_id',
                function ($subquery) use ($org, $user): void {
                    $subquery
                        ->from('user_plants')
                        ->select('plant_id')
                        ->where('organization_id', $org)
                        ->where('user_id', $user->id);
                }
            )->orWhereIn(
                'area_id',
                function ($subquery) use ($org, $user): void {
                    $subquery
                        ->from('user_areas')
                        ->select('area_id')
                        ->where('organization_id', $org)
                        ->where('user_id', $user->id);
                }
            );
        });

        foreach (['status', 'plant_id', 'area_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        $page = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(
                $data['per_page'] ?? 15,
                ['*'],
                'page',
                $data['page'] ?? 1
            );

        $inspections = new Collection($page->items());

        $summaries = $this->inspectionSummaryService
            ->summarize($inspections);

        $items = $inspections
            ->map(fn (Inspection $inspection): array => $this->dto(
                $inspection,
                $summaries[$inspection->id]
            ))
            ->values()
            ->all();

        return response()->json([
            'data' => $items,

            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, string $inspectionId): JsonResponse
    {
        Gate::authorize('viewAny', Inspection::class);
        $inspection = Inspection::query()->where('organization_id', app(TenantContext::class)->requireId())
            ->findOrFail($inspectionId);
        abort_unless(app(InspectionPolicy::class)->view($request->user(), $inspection), 404);

        return response()->json(['data' => $this->dto($inspection)]);
    }

    public function complete(Request $request, string $inspectionId): JsonResponse
    {
        $inspection = Inspection::query()->where('organization_id', app(TenantContext::class)->requireId())
            ->findOrFail($inspectionId);
        $policy = app(InspectionPolicy::class);
        abort_unless($policy->view($request->user(), $inspection), 404);
        abort_unless($policy->answer($request->user(), $inspection), 403);

        $body = json_decode($request->getContent());
        if ($request->query() !== [] || ! $request->isJson() || ! is_object($body) || get_object_vars($body) !== []) {
            throw ValidationException::withMessages(['fields' => 'La solicitud requiere un objeto JSON vacío y no admite parámetros de consulta.']);
        }

        $completed = app(InspectionCompletion::class)->complete($request->user(), $inspection->id);

        return response()->json(['data' => $this->dto($completed)]);
    }

    public function review(Request $request, string $inspectionId): JsonResponse
    {
        $inspection = Inspection::query()->where('organization_id', app(TenantContext::class)->requireId())
            ->findOrFail($inspectionId);
        $policy = app(InspectionPolicy::class);
        abort_unless($policy->view($request->user(), $inspection), 404);
        abort_unless($policy->review($request->user(), $inspection), 403);

        $body = json_decode($request->getContent());
        if ($request->query() !== [] || ! $request->isJson() || ! is_object($body)) {
            throw ValidationException::withMessages(['fields' => 'La solicitud requiere un objeto JSON y no admite parámetros de consulta.']);
        }

        $reviewed = app(InspectionReview::class)->review($request->user(), $inspection->id, get_object_vars($body));

        return response()->json(['data' => $this->dto($reviewed)]);
    }

    /**
     * Contenido histórico de la versión usada por la inspección.
     *
     * Se resuelve exclusivamente por `inspections.checklist_version_id`; no
     * consulta la versión publicada actual, el estado del checklist ni el
     * estado de la versión. No se exige acceso general al catálogo EHS.
     */
    public function checklist(Request $request, string $inspectionId): JsonResponse
    {
        Gate::authorize('viewAny', Inspection::class);
        $organizationId = app(TenantContext::class)->requireId();
        $inspection = Inspection::query()->where('organization_id', $organizationId)
            ->findOrFail($inspectionId);
        abort_unless(app(InspectionPolicy::class)->view($request->user(), $inspection), 404);

        // Opción B (PWA-FUNC-01.3.1): toda información normativa, incluso la
        // embebida en el checklist, exige `norms.view`. Sin el permiso el DTO
        // se mantiene estable con `norm_requirements: []` y no se carga la
        // relación para evitar consultas innecesarias.
        $canViewNorms = $request->user()->hasPermission(Permission::NormsView);

        $with = [
            'checklist' => fn ($query) => $query->where('organization_id', $organizationId),
            'sections' => fn ($query) => $query->where('organization_id', $organizationId)
                ->orderBy('display_order')->orderBy('id'),
            'sections.questions' => fn ($query) => $query->where('organization_id', $organizationId)
                ->where('status', true)->orderBy('display_order')->orderBy('id'),
        ];
        if ($canViewNorms) {
            $with['sections.questions.normRequirements'] = fn ($query) => $query
                ->where('norm_requirements.organization_id', $organizationId)
                ->where('question_norm_requirement.organization_id', $organizationId)
                ->orderBy('norm_requirements.code')->orderBy('norm_requirements.id');
        }

        $version = ChecklistVersion::query()
            ->where('organization_id', $organizationId)
            ->whereKey($inspection->checklist_version_id)
            ->with($with)
            ->firstOrFail();

        $sections = $version->sections->map(function ($section) use ($canViewNorms) {
            $questions = $section->questions->map(function ($question) use ($canViewNorms) {
                return [
                    'id' => $question->id,
                    'question_text' => $question->question_text,
                    'description' => $question->description,
                    'answer_type' => $question->answer_type,
                    'options' => $question->options,
                    'required' => $question->required,
                    'allows_evidence' => $question->allows_evidence,
                    'display_order' => $question->display_order,
                    'supported' => InspectionAnswerValidator::supports($question->answer_type),
                    'norm_requirements' => $canViewNorms
                        ? $question->normRequirements->map(static fn ($requirement) => [
                            'link_id' => $requirement->pivot->id,
                            'id' => $requirement->id,
                            'code' => $requirement->code,
                            'description' => $requirement->description,
                        ])->values()->all()
                        : [],
                ];
            })->values()->all();

            return [
                'id' => $section->id,
                'name' => $section->name,
                'description' => $section->description,
                'display_order' => $section->display_order,
                'questions' => $questions,
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                'inspection_id' => $inspection->id,
                'checklist' => [
                    'id' => $version->checklist_id,
                    'name' => $version->checklist?->name,
                    'status' => $version->checklist === null ? null : (bool) $version->checklist->status,
                ],
                'checklist_version' => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'status' => $version->status,
                    'published_at' => $version->published_at?->toISOString(),
                ],
                'sections' => $sections,
            ],
        ]);
    }

    private function dto(
        Inspection $inspection,
        ?array $summary = null
    ): array {
        $data = $inspection->only([
            'id',
            'organization_id',
            'user_id',
            'supervisor_id',
            'checklist_version_id',
            'plant_id',
            'area_id',
            'production_line_id',
            'status',
            'started_at',
            'completed_at',
            'supervisor_confirmed_at',
            'rejected_at',
            'supervisor_observations',
            'location_description',
            'observations',
            'created_at',
            'updated_at',
        ]);

        // Conservar el tratamiento existente de coordenadas.
        foreach (['latitude', 'longitude'] as $field) {
            $raw = $inspection->getRawOriginal($field);

            $data[$field] = $raw === null
                ? null
                : number_format((float) $raw, 7, '.', '');
        }

        $summary ??= $this->inspectionSummaryService
            ->summarizeOne($inspection);

        return array_merge($data, $summary);
    }
}
