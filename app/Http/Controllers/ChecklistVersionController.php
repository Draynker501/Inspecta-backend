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
use App\Services\ChecklistOrderingService;

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
                if (!$checklist->status) {
                    throw ValidationException::withMessages([
                        'status' =>
                            "El checklist \"{$checklist->name}\" está inactivo. "
                            . 'Debes reactivarlo antes de publicar una versión.',
                    ]);
                }

                if (
                    $version->status !== 'draft'
                    || $version->published_at !== null
                ) {
                    throw ValidationException::withMessages([
                        'status' =>
                            "La versión {$version->version} no es un borrador "
                            . 'disponible para publicación.',
                    ]);
                }
                $this->validateBeforePublishing($version);
                app(ChecklistOrderingService::class)
                    ->normalizeVersion($version);
                $previous = ChecklistVersion::where('organization_id', $organizationId)
                    ->where('checklist_id', $checklist->id)->where('status', 'published')
                    ->where('id', '!=', $version->id)->lockForUpdate()->get();
                foreach ($previous as $old) {
                    $old->update(['status' => 'archived']);
                    app(AuditLogger::class)->record('checklist_version.status_changed', $old, ['status' => 'published'], ['status' => 'archived']);
                }
            } elseif (!in_array($version->status, ['draft', 'published'], true)) {
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
        $organizationId = $version->organization_id;

        $errors = [
            'sections' => [],
            'questions' => [],
            'norms' => [],
        ];

        // Verificar la integridad multi-tenancy de la estructura.
        $foreignSection = DB::table('checklist_sections')
            ->where('checklist_version_id', $version->id)
            ->where('organization_id', '!=', $organizationId)
            ->exists();

        $foreignQuestion = DB::table('questions as q')
            ->join(
                'checklist_sections as s',
                's.id',
                '=',
                'q.checklist_section_id'
            )
            ->where('s.checklist_version_id', $version->id)
            ->where('q.organization_id', '!=', $organizationId)
            ->exists();

        if ($foreignSection || $foreignQuestion) {
            throw ValidationException::withMessages([
                'sections' =>
                    'La estructura contiene relaciones de otra organización.',
            ]);
        }

        // Obtener secciones y preguntas de la versión.
        $sections = $version->sections()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $questions = Question::whereIn(
            'checklist_section_id',
            $sections->pluck('id')
        )
            ->where('organization_id', $organizationId)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $questionsBySection = $questions->groupBy(
            'checklist_section_id'
        );

        $allowedAnswerTypes = [
            'boolean',
            'text',
            'number',
            'multiple_choice',
            'signature',
            'date',
        ];

        // Validar existencia de secciones.
        if ($sections->isEmpty()) {
            $errors['sections'][] =
                "La versión {$version->version} no contiene secciones. "
                . 'Registra al menos una sección con preguntas activas.';
        }

        // Validar individualmente cada sección.
        foreach ($sections as $section) {
            $sectionLabel = sprintf(
                'Sección %s — "%s"',
                $section->display_order,
                $section->name
            );

            $sectionQuestions = $questionsBySection->get(
                $section->id,
                collect()
            );

            $activeQuestions = $sectionQuestions->filter(
                fn($question) => (bool) $question->status
            );

            if ($sectionQuestions->isEmpty()) {
                $errors['sections'][] =
                    "{$sectionLabel}: no contiene preguntas. "
                    . 'Registra al menos una pregunta activa.';

                continue;
            }

            if ($activeQuestions->isEmpty()) {
                $total = $sectionQuestions->count();

                $errors['sections'][] =
                    "{$sectionLabel}: contiene {$total} pregunta(s), "
                    . 'pero ninguna está activa. '
                    . 'Activa al menos una pregunta o registra una nueva.';

                continue;
            }

            // Validar la configuración de cada pregunta activa.
            foreach ($activeQuestions as $question) {
                $questionText = is_string($question->question_text)
                    && trim($question->question_text) !== ''
                    ? $question->question_text
                    : 'Sin texto';

                $questionLabel = sprintf(
                    '%s | Pregunta %s — "%s"',
                    $sectionLabel,
                    $question->display_order,
                    $questionText
                );

                // Texto de la pregunta.
                if (
                    !is_string($question->question_text)
                    || trim($question->question_text) === ''
                ) {
                    $errors['questions'][] =
                        "{$questionLabel}: el texto de la pregunta "
                        . 'es obligatorio.';
                }

                // Tipo de respuesta.
                if (
                    !in_array(
                        $question->answer_type,
                        $allowedAnswerTypes,
                        true
                    )
                ) {
                    $errors['questions'][] =
                        "{$questionLabel}: el tipo de respuesta "
                        . 'no es válido.';

                    continue;
                }

                // Las preguntas que no son de opción múltiple
                // no deben contener opciones.
                if ($question->answer_type !== 'multiple_choice') {
                    if ($question->options !== null) {
                        $errors['questions'][] =
                            "{$questionLabel}: contiene opciones de "
                            . 'respuesta que no corresponden a su tipo.';
                    }

                    continue;
                }

                // Validar estructura de opciones múltiples.
                $options = $question->options;

                if (
                    !is_array($options)
                    || !array_is_list($options)
                ) {
                    $errors['questions'][] =
                        "{$questionLabel}: las opciones de respuesta "
                        . 'no tienen una estructura válida.';

                    continue;
                }

                if (count($options) < 2) {
                    $errors['questions'][] =
                        "{$questionLabel}: debe contener al menos "
                        . 'dos opciones de respuesta.';
                }

                $values = [];

                foreach ($options as $index => $option) {
                    $optionNumber = $index + 1;

                    if (
                        !is_array($option)
                        || count($option) !== 2
                        || !isset($option['value'], $option['label'])
                    ) {
                        $errors['questions'][] =
                            "{$questionLabel}: la opción {$optionNumber} "
                            . 'no tiene una estructura válida.';

                        continue;
                    }

                    $value = $option['value'];
                    $label = $option['label'];

                    if (
                        !is_string($value)
                        || trim($value) === ''
                    ) {
                        $errors['questions'][] =
                            "{$questionLabel}: la opción {$optionNumber} "
                            . 'no tiene un valor válido.';
                    } else {
                        if (mb_strlen($value) > 100) {
                            $errors['questions'][] =
                                "{$questionLabel}: el valor de la opción "
                                . "{$optionNumber} supera los 100 caracteres.";
                        }

                        if (in_array($value, $values, true)) {
                            $errors['questions'][] =
                                "{$questionLabel}: la opción {$optionNumber} "
                                . 'tiene un valor duplicado.';
                        }

                        $values[] = $value;
                    }

                    if (
                        !is_string($label)
                        || trim($label) === ''
                    ) {
                        $errors['questions'][] =
                            "{$questionLabel}: la opción {$optionNumber} "
                            . 'no tiene una etiqueta válida.';
                    } elseif (mb_strlen($label) > 150) {
                        $errors['questions'][] =
                            "{$questionLabel}: la etiqueta de la opción "
                            . "{$optionNumber} supera los 150 caracteres.";
                    }
                }
            }
        }

        // Validar vinculaciones normativas de toda la versión,
        // incluyendo las preguntas inactivas.
        $links = DB::table('question_norm_requirement as link')
            ->join(
                'questions as q',
                'q.id',
                '=',
                'link.question_id'
            )
            ->join(
                'checklist_sections as s',
                's.id',
                '=',
                'q.checklist_section_id'
            )
            ->where('s.checklist_version_id', $version->id)
            ->select([
                'link.organization_id',
                'link.norm_requirement_id',
                'q.id as question_id',
                'q.question_text',
                'q.display_order as question_order',
                'q.status as question_status',
                's.name as section_name',
                's.display_order as section_order',
            ])
            ->orderBy('s.display_order')
            ->orderBy('q.display_order')
            ->orderBy('link.id')
            ->get();

        // Mantener el orden de bloqueos utilizado originalmente.
        $requirementIds = $links
            ->pluck('norm_requirement_id')
            ->unique()
            ->sort()
            ->values();

        $requirements = DB::table('norm_requirements')
            ->whereIn('id', $requirementIds)
            ->orderBy('id')
            ->get([
                'id',
                'organization_id',
                'norm_id',
            ]);

        $normIds = $requirements
            ->pluck('norm_id')
            ->unique()
            ->sort()
            ->values();

        $norms = DB::table('norms')
            ->whereIn('id', $normIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $requirements = DB::table('norm_requirements')
            ->whereIn('id', $requirementIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($links as $link) {
            $requirement = $requirements->get(
                $link->norm_requirement_id
            );

            $norm = $requirement
                ? $norms->get($requirement->norm_id)
                : null;

            $questionLabel = sprintf(
                'Sección %s — "%s" | Pregunta %s — "%s"%s',
                $link->section_order,
                $link->section_name,
                $link->question_order,
                $link->question_text,
                $link->question_status ? '' : ' (Inactiva)'
            );

            // No exponer información de requisitos o normas
            // pertenecientes a otras organizaciones.
            if (
                $link->organization_id !== $organizationId
                || !$requirement
                || $requirement->organization_id !== $organizationId
                || !$norm
                || $norm->organization_id !== $organizationId
            ) {
                $errors['norms'][] =
                    "{$questionLabel}: contiene una vinculación "
                    . 'normativa inválida o ajena a la organización.';

                continue;
            }

            // Identificar exactamente la norma inactiva.
            if (!$norm->status) {
                $normLabel = $norm->code;

                if (!empty($norm->version)) {
                    $normLabel .= " (Versión {$norm->version})";
                }

                $errors['norms'][] =
                    "{$questionLabel}: el requisito "
                    . "{$requirement->code} pertenece a la norma "
                    . "\"{$normLabel}\", que está inactiva. "
                    . 'Reactiva la norma o desvincula el requisito.';
            }
        }

        // Eliminar categorías que no contienen errores.
        $errors = array_filter(
            $errors,
            fn(array $messages): bool => $messages !== []
        );

        // Devolver todos los incumplimientos en una sola respuesta.
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
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

        return response()->json([
            'data' => $versions->items(),
            'meta' => [
                'current_page' => $versions->currentPage(),
                'last_page' => $versions->lastPage(),
                'per_page' => $versions->perPage(),
                'total' => $versions->total(),
            ]
        ]);
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

            if (
                DB::table('checklist_versions')->where('checklist_id', $checklist->id)
                    ->where('version', $validated['version'])->exists()
            ) {
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
            if (
                ChecklistVersion::where('checklist_id', $checklist->id)->where('version', $validated['version'])
                    ->where('id', '!=', $version->id)->exists()
            ) {
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
