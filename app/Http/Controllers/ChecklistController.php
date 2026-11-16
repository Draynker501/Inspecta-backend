<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChecklistController extends TenantController
{
    // Lectura y creación adaptadas selectivamente de origin/api e57aaf7:
    // se añaden autorización, aislamiento y auditoría transaccional.
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Checklist::class);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $checklists = Checklist::query()
            ->where('organization_id', app(TenantContext::class)->requireId())
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $checklists->items(),
            'meta' => [
                'current_page' => $checklists->currentPage(),
                'last_page' => $checklists->lastPage(),
                'per_page' => $checklists->perPage(),
                'total' => $checklists->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Checklist::class);

        $unexpected = array_diff(array_keys($request->all()), ['name', 'description']);
        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'fields' => 'La solicitud contiene campos no permitidos.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                // TEXT de MariaDB admite como máximo 65 535 bytes, no caracteres.
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('La descripción supera el máximo de 65535 bytes.');
                }
            }],
        ]);
        $organizationId = app(TenantContext::class)->requireId();

        $checklist = DB::transaction(function () use ($validated, $organizationId): Checklist {
            $checklist = Checklist::create([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'status' => true,
            ]);

            app(AuditLogger::class)->record('checklist.created', $checklist, [], [
                'name' => $checklist->name,
                'status' => $checklist->status,
            ]);

            return $checklist;
        });

        return response()->json(['data' => $checklist], 201);
    }

    public function show(string $checklistId): JsonResponse
    {
        Gate::authorize('viewAny', Checklist::class);

        $checklist = Checklist::query()
            ->where('organization_id', app(TenantContext::class)->requireId())
            ->findOrFail($checklistId);
        Gate::authorize('view', $checklist);

        return response()->json(['data' => $checklist]);
    }

    // Adaptado de origin/api e57aaf7; toda mutación se serializa por organización.
    public function update(Request $request, string $checklistId): JsonResponse
    {
        Gate::authorize('create', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $checklist = DB::transaction(function () use ($request, $checklistId, $organizationId): Checklist {
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();
            $checklist = Checklist::where('organization_id', $organizationId)->lockForUpdate()->findOrFail($checklistId);
            Gate::authorize('update', $checklist);
            if (array_diff(array_keys($request->all()), ['name', 'description']) !== []) {
                throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
            }
            $validated = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:150'],
                'description' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 65535) {
                        $fail('La descripción supera el máximo de 65535 bytes.');
                    }
                }],
            ]);
            $before = $checklist->only(array_keys($validated));
            $checklist->fill($validated);
            if ($checklist->isDirty()) {
                $checklist->save();
                app(AuditLogger::class)->record('checklist.updated', $checklist, $before, $checklist->only(array_keys($validated)));
            }

            return $checklist->refresh();
        });

        return response()->json(['data' => $checklist]);
    }

    public function updateStatus(Request $request, string $checklistId): JsonResponse
    {
        Gate::authorize('create', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $checklist = DB::transaction(function () use ($request, $checklistId, $organizationId): Checklist {
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();
            $checklist = Checklist::where('organization_id', $organizationId)->lockForUpdate()->findOrFail($checklistId);
            Gate::authorize('update', $checklist);
            if (array_diff(array_keys($request->all()), ['status']) !== []) {
                throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
            }
            $validated = $request->validate(['status' => ['required', 'boolean']]);
            $old = $checklist->status;
            $checklist->status = $validated['status'];
            if ($checklist->isDirty()) {
                $checklist->save();
                app(AuditLogger::class)->record('checklist.status_changed', $checklist, ['status' => $old], ['status' => $checklist->status]);
            }

            return $checklist->refresh();
        });

        return response()->json(['data' => $checklist]);
    }

    public function activeVersion(string $checklistId): JsonResponse
    {
        Gate::authorize('viewAny', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $checklist = Checklist::where('organization_id', $organizationId)->where('status', true)->findOrFail($checklistId);
        Gate::authorize('view', $checklist);
        $version = $checklist->checklistVersions()->where('organization_id', $organizationId)
            ->where('status', 'published')->with([
                'sections' => fn ($q) => $q->where('organization_id', $organizationId)->orderBy('display_order')->orderBy('id'),
                'sections.questions' => fn ($q) => $q->where('organization_id', $organizationId)->where('status', true)->orderBy('display_order')->orderBy('id'),
                'sections.questions.normRequirements' => fn ($q) => $q->where('norm_requirements.organization_id', $organizationId)
                    ->where('question_norm_requirement.organization_id', $organizationId)->orderBy('norm_requirements.code')->orderBy('norm_requirements.id'),
            ])->orderBy('id')->firstOrFail();
        $sections = $version->sections->map(function ($section) {
            $data = $section->toArray();
            $data['questions'] = $section->questions->map(function ($question) {
                $data = $question->toArray();
                $data['norm_requirements'] = $question->normRequirements->map(function ($requirement) {
                    return [
                        'link_id' => $requirement->pivot->id,
                        'id' => $requirement->id,
                        'code' => $requirement->code,
                        'description' => $requirement->description,
                    ];
                })->values()->all();

                return $data;
            })->values()->all();

            return $data;
        });
        $data = $checklist->toArray();
        $data['active_version'] = [
            'id' => $version->id, 'version' => $version->version, 'status' => $version->status,
            'published_at' => $version->published_at, 'sections' => $sections->values()->all(),
        ];

        return response()->json(['data' => $data]);
    }
}
