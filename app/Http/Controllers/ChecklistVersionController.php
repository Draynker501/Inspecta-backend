<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\ChecklistVersion;
use App\Models\Question;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChecklistVersionController extends TenantController
{
    // Adaptado de origin/api d292fed: todas las escrituras comparten el bloqueo del checklist.
    public function updateStatus(Request $request, string $checklistId, string $versionId): JsonResponse
    {
        Gate::authorize('create', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();

        $version = DB::transaction(function () use ($request, $checklistId, $versionId, $organizationId): ChecklistVersion {
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();
            $checklist = Checklist::where('organization_id', $organizationId)->lockForUpdate()->findOrFail($checklistId);
            Gate::authorize('update', $checklist);
            $version = ChecklistVersion::where('organization_id', $organizationId)
                ->where('checklist_id', $checklist->id)->lockForUpdate()->findOrFail($versionId);

            if (array_diff(array_keys($request->all()), ['status']) !== []) {
                throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
            }
            $validated = $request->validate(['status' => ['required', Rule::in(['published', 'archived'])]]);
            $newStatus = $validated['status'];
            if ($version->status === 'archived') {
                throw ValidationException::withMessages(['status' => 'Una versión archivada no puede cambiar de estado.']);
            }
            if ($version->status === $newStatus) {
                return $version;
            }
            if ($newStatus === 'published') {
                if ($version->status !== 'draft' || $version->published_at !== null || ! $checklist->status) {
                    throw ValidationException::withMessages(['status' => 'Solo se puede publicar un borrador nunca publicado de un checklist activo.']);
                }
                $this->validateBeforePublishing($version);
                $previous = ChecklistVersion::where('organization_id', $organizationId)
                    ->where('checklist_id', $checklist->id)->where('status', 'published')
                    ->where('id', '!=', $version->id)->lockForUpdate()->get();
                foreach ($previous as $old) {
                    $old->update(['status' => 'archived']);
                    app(AuditLogger::class)->record('checklist_version.status_changed', $old, ['status' => 'published'], ['status' => 'archived']);
                }
            } elseif (! in_array($version->status, ['draft', 'published'], true)) {
                throw ValidationException::withMessages(['status' => 'Transición de estado inválida.']);
            }

            $oldStatus = $version->status;
            $changes = ['status' => $newStatus];
            if ($newStatus === 'published') {
                $changes['published_at'] = now();
            }
            $version->update($changes);
            app(AuditLogger::class)->record('checklist_version.status_changed', $version, ['status' => $oldStatus], ['status' => $newStatus]);

            return $version->refresh();
        });

        return response()->json(['data' => $version]);
    }

    private function validateBeforePublishing(ChecklistVersion $version): void
    {
        // Las FK históricas no son compuestas: no ocultar relaciones corruptas
        // mediante los scopes de tenant al validar la estructura completa.
        $foreignSection = DB::table('checklist_sections')
            ->where('checklist_version_id', $version->id)
            ->where('organization_id', '!=', $version->organization_id)->exists();
        $foreignQuestion = DB::table('questions as q')
            ->join('checklist_sections as s', 's.id', '=', 'q.checklist_section_id')
            ->where('s.checklist_version_id', $version->id)
            ->where('q.organization_id', '!=', $version->organization_id)->exists();
        if ($foreignSection || $foreignQuestion) {
            throw ValidationException::withMessages(['sections' => 'La estructura contiene relaciones de otra organización.']);
        }

        if (! $version->sections()->exists() || $version->sections()->whereDoesntHave('questions', fn ($query) => $query->where('status', true))->exists()) {
            throw ValidationException::withMessages(['sections' => 'Cada versión necesita secciones con preguntas activas.']);
        }

        $questions = Question::whereIn('checklist_section_id', $version->sections()->select('id'))
            ->where('status', true)->get();
        foreach ($questions as $question) {
            if (! is_string($question->question_text) || trim($question->question_text) === ''
                || ! in_array($question->answer_type, ['boolean', 'text', 'number', 'multiple_choice', 'signature', 'date'], true)) {
                throw ValidationException::withMessages(['questions' => 'La pregunta tiene datos inválidos.']);
            }
            if ($question->answer_type !== 'multiple_choice') {
                if ($question->options !== null) {
                    throw ValidationException::withMessages(['questions' => 'La pregunta tiene opciones inválidas.']);
                }

                continue;
            }
            $options = $question->options;
            if (! is_array($options) || ! array_is_list($options) || count($options) < 2) {
                throw ValidationException::withMessages(['questions' => 'La pregunta tiene opciones inválidas.']);
            }
            $values = [];
            foreach ($options as $option) {
                if (! is_array($option) || count($option) !== 2 || ! isset($option['value'], $option['label'])
                    || ! is_string($option['value']) || trim($option['value']) === ''
                    || ! is_string($option['label']) || trim($option['label']) === ''
                    || mb_strlen($option['value']) > 100 || mb_strlen($option['label']) > 150
                    || in_array($option['value'], $values, true)) {
                    throw ValidationException::withMessages(['questions' => 'La pregunta tiene opciones inválidas.']);
                }
                $values[] = $option['value'];
            }
        }

        // El bloqueo previo de organización serializa también altas y bajas de vínculos.
        $organizationId = $version->organization_id;
        $links = DB::table('question_norm_requirement as link')
            ->join('questions as q', 'q.id', '=', 'link.question_id')
            ->join('checklist_sections as s', 's.id', '=', 'q.checklist_section_id')
            ->where('s.checklist_version_id', $version->id)
            ->select('link.organization_id', 'link.norm_requirement_id')->get();
        $requirementIds = $links->pluck('norm_requirement_id')->unique()->sort()->values();
        $requirements = DB::table('norm_requirements')->whereIn('id', $requirementIds)
            ->orderBy('id')->get(['id', 'organization_id', 'norm_id']);
        $normIds = $requirements->pluck('norm_id')->unique()->sort()->values();
        $norms = DB::table('norms')->whereIn('id', $normIds)->orderBy('id')->lockForUpdate()->get()
            ->keyBy('id');
        $requirements = DB::table('norm_requirements')->whereIn('id', $requirementIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($links as $link) {
            $requirement = $requirements->get($link->norm_requirement_id);
            $norm = $requirement ? $norms->get($requirement->norm_id) : null;
            if ($link->organization_id !== $organizationId || ! $requirement
                || $requirement->organization_id !== $organizationId || ! $norm
                || $norm->organization_id !== $organizationId || ! $norm->status) {
                throw ValidationException::withMessages(['norms' => 'Los requisitos vinculados deben pertenecer a normas activas de la organización.']);
            }
        }
    }

    /**
     * Display a listing of the resource.
     */
    // Adaptado del listado de origin/api d292fed; autorización antes de resolver.
    public function index(Request $request, string $checklistId): JsonResponse
    {
        Gate::authorize('viewAny', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $checklist = Checklist::where('organization_id', $organizationId)->findOrFail($checklistId);
        Gate::authorize('view', $checklist);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $versions = $checklist->checklistVersions()
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at')->orderBy('id')
            ->paginate($validated['per_page'] ?? 15, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json(['data' => $versions->items(), 'meta' => [
            'current_page' => $versions->currentPage(), 'last_page' => $versions->lastPage(),
            'per_page' => $versions->perPage(), 'total' => $versions->total(),
        ]]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    // Adaptado de origin/api d292fed con autorización y auditoría atómica.
    public function store(Request $request, string $checklistId): JsonResponse
    {
        Gate::authorize('create', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();

        $version = DB::transaction(function () use ($request, $checklistId, $organizationId): ChecklistVersion {
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();
            $checklist = Checklist::where('organization_id', $organizationId)
                ->lockForUpdate()->findOrFail($checklistId);
            Gate::authorize('update', $checklist);

            if (array_diff(array_keys($request->all()), ['version']) !== []) {
                throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
            }
            $validated = $request->validate(['version' => ['required', 'string', 'max:20']]);

            if (DB::table('checklist_versions')->where('checklist_id', $checklist->id)
                ->where('version', $validated['version'])->exists()) {
                throw ValidationException::withMessages(['version' => 'La versión ya existe.']);
            }

            try {
                $version = ChecklistVersion::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organizationId,
                    'checklist_id' => $checklist->id,
                    'version' => $validated['version'],
                    'status' => 'draft',
                    'created_by' => $request->user()->id,
                    'published_at' => null,
                ]);
            } catch (QueryException $exception) {
                $info = $exception->errorInfo;
                $driver = DB::connection($exception->getConnectionName())->getDriverName();
                $sqliteConflict = $driver === 'sqlite' && ($info[0] ?? null) === '23000'
                    && (int) ($info[1] ?? 0) === 19
                    && ($info[2] ?? null) === 'UNIQUE constraint failed: checklist_versions.checklist_id, checklist_versions.version';
                $mariaDbConflict = in_array($driver, ['mariadb', 'mysql'], true) && ($info[0] ?? null) === '23000'
                    && (int) ($info[1] ?? 0) === 1062 && is_string($info[2] ?? null)
                    && str_ends_with($info[2], " for key 'checklist_versions_checklist_version_unique'");
                if ($sqliteConflict || $mariaDbConflict) {
                    throw ValidationException::withMessages(['version' => 'La versión ya existe.']);
                }
                throw $exception;
            }
            app(AuditLogger::class)->record('checklist_version.created', $version, [], [
                'checklist_id' => $checklist->id,
                'version' => $version->version,
                'status' => $version->status,
            ]);

            return $version;
        });

        return response()->json(['data' => $version], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $checklistId, string $versionId): JsonResponse
    {
        Gate::authorize('viewAny', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $checklist = Checklist::where('organization_id', $organizationId)->findOrFail($checklistId);
        Gate::authorize('view', $checklist);
        $version = ChecklistVersion::where('organization_id', $organizationId)
            ->where('checklist_id', $checklist->id)->findOrFail($versionId);

        return response()->json(['data' => $version]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ChecklistVersion $checklistVersion)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $checklistId, string $versionId): JsonResponse
    {
        Gate::authorize('create', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $version = DB::transaction(function () use ($request, $checklistId, $versionId, $organizationId): ChecklistVersion {
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();
            $checklist = Checklist::where('organization_id', $organizationId)->lockForUpdate()->findOrFail($checklistId);
            Gate::authorize('update', $checklist);
            $version = ChecklistVersion::where('organization_id', $organizationId)->where('checklist_id', $checklist->id)
                ->lockForUpdate()->findOrFail($versionId);
            if ($version->status !== 'draft' || $version->published_at !== null) {
                throw ValidationException::withMessages(['status' => 'Solo se pueden editar borradores nunca publicados.']);
            }
            if (array_diff(array_keys($request->all()), ['version']) !== []) {
                throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
            }
            $validated = $request->validate(['version' => ['required', 'string', 'max:20']]);
            if (ChecklistVersion::where('checklist_id', $checklist->id)->where('version', $validated['version'])
                ->where('id', '!=', $version->id)->exists()) {
                throw ValidationException::withMessages(['version' => 'La versión ya existe.']);
            }
            $old = $version->version;
            if ($old !== $validated['version']) {
                $version->update($validated);
                app(AuditLogger::class)->record('checklist_version.updated', $version, ['version' => $old], ['version' => $version->version]);
            }

            return $version->refresh();
        });

        return response()->json(['data' => $version]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ChecklistVersion $checklistVersion)
    {
        //
    }
}
