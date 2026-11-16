<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Organization;
use App\Models\Question;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuestionController extends TenantController
{
    /**
     * Display a listing of the resource.
     */
    // Adaptado del listado de origin/api 958f0b6; se verifica toda la jerarquía.
    public function index(Request $request, string $sectionId): JsonResponse
    {
        Gate::authorize('viewAny', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $section = ChecklistSection::where('organization_id', $organizationId)
            ->whereHas('checklistVersion', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->whereHas('checklist', fn ($checklists) => $checklists->where('organization_id', $organizationId)))
            ->findOrFail($sectionId);
        Gate::authorize('view', $section->checklistVersion->checklist);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $questions = $section->questions()->where('organization_id', $organizationId)
            ->orderBy('display_order')->orderBy('id')
            ->paginate($validated['per_page'] ?? 15, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json(['data' => $questions->items(), 'meta' => [
            'current_page' => $questions->currentPage(), 'last_page' => $questions->lastPage(),
            'per_page' => $questions->perPage(), 'total' => $questions->total(),
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
    // Adaptado de origin/api 958f0b6 y 0d97d3b; borrador y auditoría atómicos.
    public function store(Request $request, string $sectionId): JsonResponse
    {
        Gate::authorize('create', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $question = DB::transaction(function () use ($request, $sectionId, $organizationId): Question {
            Organization::query()->lockForUpdate()->findOrFail($organizationId);
            $candidate = ChecklistSection::where('organization_id', $organizationId)
                ->whereHas('checklistVersion', fn ($query) => $query->where('organization_id', $organizationId)
                    ->whereHas('checklist', fn ($parent) => $parent->where('organization_id', $organizationId)))
                ->findOrFail($sectionId);
            $candidateVersion = ChecklistVersion::where('organization_id', $organizationId)->findOrFail($candidate->checklist_version_id);
            $checklist = Checklist::where('organization_id', $organizationId)->lockForUpdate()->findOrFail($candidateVersion->checklist_id);
            Gate::authorize('update', $checklist);
            $version = ChecklistVersion::where('organization_id', $organizationId)->where('checklist_id', $checklist->id)
                ->lockForUpdate()->findOrFail($candidateVersion->id);
            $section = ChecklistSection::where('organization_id', $organizationId)->where('checklist_version_id', $version->id)
                ->lockForUpdate()->findOrFail($sectionId);
            if ($version->status !== 'draft' || $version->published_at !== null) {
                throw ValidationException::withMessages(['version' => 'Solo se pueden modificar versiones en borrador nunca publicadas.']);
            }
            if (array_diff(array_keys($request->all()), ['question_text', 'description', 'answer_type', 'options', 'display_order', 'required', 'allows_evidence']) !== []) {
                throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
            }
            $validated = $request->validate($this->questionRules(true));
            $options = $validated['options'] ?? null;
            if (($validated['answer_type'] === 'multiple_choice' && ($options === null || count($options) < 2))
                || ($validated['answer_type'] !== 'multiple_choice' && $options !== null)) {
                throw ValidationException::withMessages(['options' => 'Las opciones no corresponden al tipo de respuesta.']);
            }
            $max = (int) DB::table('questions')->where('checklist_section_id', $section->id)->max('display_order');
            $order = $validated['display_order'] ?? $max + 1;
            if ($order > 2147483647 || DB::table('questions')->where('checklist_section_id', $section->id)->where('display_order', $order)->exists()) {
                throw ValidationException::withMessages(['display_order' => 'El orden no está disponible.']);
            }
            try {
                $question = Question::create([
                    'id' => (string) Str::uuid7(), 'organization_id' => $organizationId,
                    'checklist_section_id' => $section->id, 'question_text' => $validated['question_text'],
                    'description' => $validated['description'] ?? null, 'answer_type' => $validated['answer_type'],
                    'options' => $options, 'display_order' => $order, 'required' => $validated['required'] ?? true,
                    'allows_evidence' => $validated['allows_evidence'] ?? false, 'status' => true,
                ]);
            } catch (QueryException $exception) {
                $info = $exception->errorInfo;
                $driver = DB::connection($exception->getConnectionName())->getDriverName();
                $sqlite = $driver === 'sqlite' && ($info[0] ?? null) === '23000' && (int) ($info[1] ?? 0) === 19
                    && ($info[2] ?? null) === 'UNIQUE constraint failed: questions.checklist_section_id, questions.display_order';
                $maria = in_array($driver, ['mariadb', 'mysql'], true) && ($info[0] ?? null) === '23000'
                    && (int) ($info[1] ?? 0) === 1062 && is_string($info[2] ?? null)
                    && str_ends_with($info[2], " for key 'questions_section_order_unique'");
                if ($sqlite || $maria) {
                    throw ValidationException::withMessages(['display_order' => 'El orden no está disponible.']);
                }
                throw $exception;
            }
            app(AuditLogger::class)->record('question.created', $question, [], [
                'checklist_section_id' => $section->id, 'answer_type' => $question->answer_type,
                'display_order' => $question->display_order, 'status' => $question->status,
            ]);

            return $question;
        });

        return response()->json(['data' => $question], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $questionId): JsonResponse
    {
        Gate::authorize('viewAny', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $question = Question::where('organization_id', $organizationId)
            ->whereHas('checklistSection', fn ($sections) => $sections
                ->where('organization_id', $organizationId)
                ->whereHas('checklistVersion', fn ($versions) => $versions
                    ->where('organization_id', $organizationId)
                    ->whereHas('checklist', fn ($checklists) => $checklists->where('organization_id', $organizationId))))
            ->findOrFail($questionId);
        Gate::authorize('view', $question->checklistSection->checklistVersion->checklist);

        $question->unsetRelation('checklistSection');

        return response()->json(['data' => $question]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Question $question)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    // Adaptado de origin/api 0d97d3b y 6dc9429; autorización, locks y auditoría reforzados.
    public function update(Request $request, string $questionId): JsonResponse
    {
        Gate::authorize('create', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $question = DB::transaction(function () use ($request, $questionId, $organizationId): Question {
            Organization::query()->lockForUpdate()->findOrFail($organizationId);
            $candidate = Question::where('organization_id', $organizationId)->findOrFail($questionId);
            $candidateSection = ChecklistSection::where('organization_id', $organizationId)->findOrFail($candidate->checklist_section_id);
            $candidateVersion = ChecklistVersion::where('organization_id', $organizationId)->findOrFail($candidateSection->checklist_version_id);
            $checklist = Checklist::where('organization_id', $organizationId)->lockForUpdate()->findOrFail($candidateVersion->checklist_id);
            Gate::authorize('update', $checklist);
            $version = ChecklistVersion::where('organization_id', $organizationId)->where('checklist_id', $checklist->id)
                ->lockForUpdate()->findOrFail($candidateVersion->id);
            $section = ChecklistSection::where('organization_id', $organizationId)->where('checklist_version_id', $version->id)
                ->lockForUpdate()->findOrFail($candidateSection->id);
            $question = Question::where('organization_id', $organizationId)->where('checklist_section_id', $section->id)
                ->lockForUpdate()->findOrFail($questionId);
            if ($version->status !== 'draft' || $version->published_at !== null) {
                throw ValidationException::withMessages(['version' => 'Solo se pueden modificar versiones en borrador nunca publicadas.']);
            }
            if (array_diff(array_keys($request->all()), ['question_text', 'description', 'answer_type', 'options', 'display_order', 'required', 'allows_evidence']) !== []) {
                throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
            }
            $validated = $request->validate($this->questionRules(false));
            $answerType = $validated['answer_type'] ?? $question->answer_type;
            $options = array_key_exists('options', $validated) ? $validated['options'] : $question->options;
            // Como origin/api, al cambiar de tipo se eliminan las opciones previas;
            // las opciones enviadas explícitamente para otro tipo se rechazan.
            if ($answerType !== 'multiple_choice' && ! array_key_exists('options', $validated)) {
                $options = null;
            }
            Validator::make(['options' => $options], array_intersect_key($this->questionRules(false), array_flip([
                'options', 'options.*', 'options.*.value', 'options.*.label',
            ])))->validate();
            if (($answerType === 'multiple_choice' && ($options === null || count($options) < 2))
                || ($answerType !== 'multiple_choice' && $options !== null)) {
                throw ValidationException::withMessages(['options' => 'Las opciones no corresponden al tipo de respuesta.']);
            }
            if (isset($validated['display_order']) && DB::table('questions')->where('checklist_section_id', $section->id)
                ->where('id', '!=', $question->id)->where('display_order', $validated['display_order'])->exists()) {
                throw ValidationException::withMessages(['display_order' => 'El orden no está disponible.']);
            }
            if ($options !== $question->options || array_key_exists('options', $validated)) {
                $validated['options'] = $options;
            }
            $old = $question->only(array_keys($validated));
            $question->fill($validated);
            if ($question->isDirty()) {
                $question->save();
                app(AuditLogger::class)->record('question.updated', $question, $old, $question->only(array_keys($validated)));
            }

            return $question;
        });

        return response()->json(['data' => $question]);
    }

    private function questionRules(bool $creating): array
    {
        $textLimit = function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && strlen($value) > 65535) {
                $fail('El texto supera el máximo de 65535 bytes.');
            }
        };

        return [
            'question_text' => [$creating ? 'required' : 'sometimes', 'required', 'string', $textLimit],
            'description' => ['sometimes', 'nullable', 'string', $textLimit],
            'answer_type' => [$creating ? 'required' : 'sometimes', 'required', Rule::in(['boolean', 'text', 'number', 'multiple_choice', 'signature', 'date'])],
            'options' => ['sometimes', 'nullable', 'array', 'list'],
            'options.*' => ['required', 'array:value,label'],
            'options.*.value' => ['required', 'string', 'max:100', 'distinct:strict'],
            'options.*.label' => ['required', 'string', 'max:150'],
            'display_order' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'required' => ['sometimes', 'boolean'],
            'allows_evidence' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Question $question)
    {
        //
    }
}
