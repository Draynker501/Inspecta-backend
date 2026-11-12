<?php

namespace App\Http\Controllers;

use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Question;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuestionController extends TenantController
{
    /**
     * GET /api/sections/{sectionId}/questions
     * Lista las preguntas de una sección.
     */
    public function index(Request $request, string $sectionId): JsonResponse
    {
        $section = ChecklistSection::findOrFail($sectionId);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $questions = $section->questions()
            ->orderBy('display_order')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'data' => $questions->items(),
            'meta' => [
                'current_page' => $questions->currentPage(),
                'last_page' => $questions->lastPage(),
                'per_page' => $questions->perPage(),
                'total' => $questions->total(),
            ],
        ]);
    }

    /**
     * POST /api/sections/{sectionId}/questions
     * Crea una pregunta dentro de una sección.
     */
    public function store(Request $request, string $sectionId, TenantContext $tenantContext): JsonResponse
    {
        $validated = $request->validate(
            $this->questionRules(true)
        );

        $this->validateOptions(
            $validated['answer_type'],
            $validated['options'] ?? null
        );

        $question = DB::transaction(function () use ($sectionId, $validated, $tenantContext) {
            $section = ChecklistSection::findOrFail($sectionId);

            $version = ChecklistVersion::query()
                ->lockForUpdate()
                ->findOrFail($section->checklist_version_id);

            $this->ensureDraft($version);

            $displayOrder = $validated['display_order']
                ?? max(1, ((int) $section->questions()->max('display_order')) + 1);

            $orderExists = $section->questions()
                ->where('display_order', $displayOrder)
                ->exists();

            if ($orderExists) {
                throw ValidationException::withMessages([
                    'display_order' => 'Ya existe una pregunta con ese orden en la sección.',
                ]);
            }

            return Question::create([
                'id' => (string) Str::uuid(),
                'checklist_section_id' => $section->id,
                'organization_id' => $tenantContext->requireId(),
                'question_text' => $validated['question_text'],
                'description' => $validated['description'] ?? null,
                'answer_type' => $validated['answer_type'],
                'options' => $validated['options'] ?? null,
                'display_order' => $displayOrder,
                'required' => $validated['required'] ?? true,
                'allows_evidence' => $validated['allows_evidence'] ?? false,
                'status' => true,
            ]);
        });

        return response()->json([
            'data' => $question,
        ], 201);
    }

    /**
     * GET /api/questions/{id}
     * Consulta una pregunta específica.
     */
    public function show(string $id): JsonResponse
    {
        $question = Question::findOrFail($id);

        return response()->json([
            'data' => $question,
        ]);
    }

    /**
     * PUT /api/questions/{id}
     * Actualiza una pregunta de una versión en borrador.
     */

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(
            $this->questionRules()
        );

        $question = DB::transaction(function () use ($id, $validated) {
            $question = Question::findOrFail($id);

            $versionId = $question->checklistSection
                ->checklist_version_id;

            $version = ChecklistVersion::query()
                ->lockForUpdate()
                ->findOrFail($versionId);

            $this->ensureDraft($version);

            if (array_key_exists('display_order', $validated)) {
                $orderExists = Question::query()
                    ->where('checklist_section_id', $question->checklist_section_id)
                    ->where('display_order', $validated['display_order'])
                    ->where('id', '!=', $question->id)
                    ->exists();

                if ($orderExists) {
                    throw ValidationException::withMessages([
                        'display_order' => 'Ya existe otra pregunta con ese orden en la sección.',
                    ]);
                }
            }

            $answerType = $validated['answer_type']
                ?? $question->answer_type;

            $options = array_key_exists('options', $validated) ? $validated['options'] : $question->options;

            // Si el usuario cambia a un tipo distinto de multiple_choice, se eliminan las opciones anteriores.
            if ($answerType !== 'multiple_choice') {
                $options = null;
            }

            $this->validateOptions($answerType, $options);

            $validated['options'] = $options;

            $question->update($validated);

            return $question->refresh();
        });

        return response()->json([
            'data' => $question,
        ]);
    }

    /**
     * PATCH /api/questions/{id}/status
     * Alterna el estado de una pregunta.
     */
    public function updateStatus(string $id): JsonResponse
    {
        $question = DB::transaction(function () use ($id) {
            $question = Question::findOrFail($id);

            $versionId = $question->checklistSection
                ->checklist_version_id;

            $version = ChecklistVersion::query()
                ->lockForUpdate()
                ->findOrFail($versionId);

            $this->ensureDraft($version);

            // Bloquea la fila para evitar alternancias simultáneas.
            $question = Question::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $question->update([
                'status' => !$question->status,
            ]);

            return $question->refresh();
        });

        return response()->json([
            'data' => $question,
        ]);
    }

    /**
     * Verifica que una versión permita modificaciones.
     */
    private function ensureDraft(ChecklistVersion $version): void
    {
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages([
                'version' => 'Solo se pueden modificar preguntas de versiones en borrador.',
            ]);
        }
    }


    private function questionRules(bool $creating = false): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'question_text' => [$required, 'required', 'string', 'max:65535',],
            'description' => ['sometimes', 'nullable', 'string'],
            'answer_type' => [
                $required,
                Rule::in([
                    'boolean',
                    'text',
                    'number',
                    'multiple_choice',
                    'signature',
                    'date',
                ]),
            ],
            'options' => ['sometimes', 'nullable', 'array'],
            'options.*.value' => ['required', 'string', 'max:100', 'distinct',],
            'options.*.label' => ['required', 'string', 'max:150',],
            'display_order' => ['sometimes', 'integer', 'min:1'],
            'required' => ['sometimes', 'boolean'],
            'allows_evidence' => ['sometimes', 'boolean'],
        ];
    }

    private function validateOptions(string $answerType, ?array $options): void
    {
        if ($answerType === 'multiple_choice') {
            if ($options === null || count($options) < 2) {
                throw ValidationException::withMessages([
                    'options' => 'Las preguntas de opción múltiple necesitan al menos dos opciones.',
                ]);
            }

            return;
        }

        if (!empty($options)) {
            throw ValidationException::withMessages([
                'options' => 'Este tipo de pregunta no admite opciones.',
            ]);
        }
    }

}
