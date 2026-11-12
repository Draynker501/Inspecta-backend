<?php

namespace App\Http\Controllers;

use App\Models\ChecklistVersion;
use App\Models\NormRequirement;
use App\Models\Question;
use App\Models\QuestionNormRequirement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuestionNormRequirementController extends TenantController
{
    /**
     * GET /api/questions/{questionId}/norm-requirements
     * Lista los requisitos normativos de una pregunta.
     */

    public function index(Request $request, string $questionId): JsonResponse
    {
        $question = Question::findOrFail($questionId);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $requirements = $question->normRequirements()
            ->orderBy('norm_requirements.code')
            ->orderBy('norm_requirements.id')
            ->paginate($validated['per_page'] ?? 15);

        $data = $requirements->getCollection()
            ->map(function ($requirement) {
                $item = $requirement->toArray();

                unset($item['pivot']);

                return [
                    'link_id' => $requirement->pivot->id,
                    ...$item,
                ];
            });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $requirements->currentPage(),
                'last_page' => $requirements->lastPage(),
                'per_page' => $requirements->perPage(),
                'total' => $requirements->total(),
            ],
        ]);
    }


    /**
     * POST /api/questions/{questionId}/norm-requirements
     * Vincula un requisito normativo a una pregunta.
     */
    public function store(Request $request, string $questionId, TenantContext $tenantContext): JsonResponse
    {
        $validated = $request->validate([
            'norm_requirement_id' => ['required', 'uuid',],
        ]);

        $link = DB::transaction(function () use ($questionId, $validated, $tenantContext) {
            $question = Question::findOrFail($questionId);

            // Identificar la versión del checklist.
            $versionId = $question->checklistSection->checklist_version_id;

            // Bloquear la versión antes de modificar vínculos.
            $version = ChecklistVersion::query()
                ->lockForUpdate()
                ->findOrFail($versionId);

            $this->ensureDraft($version);

            // Comprobar que el requisito exista dentro de la organización activa.
            $requirement = NormRequirement::findOrFail(
                $validated['norm_requirement_id']
            );

            // Evitar duplicar la misma asociación.
            $exists = QuestionNormRequirement::query()
                ->where('question_id', $question->id)
                ->where('norm_requirement_id', $requirement->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'norm_requirement_id' =>
                        'Este requisito ya está vinculado a la pregunta.',
                ]);
            }

            return QuestionNormRequirement::create([
                'question_id' => $question->id,
                'norm_requirement_id' => $requirement->id,
                'organization_id' => $tenantContext->requireId(),
            ]);
        });

        return response()->json([
            'data' => [
                'link_id' => $link->id,
                'question_id' => $link->question_id,
                'norm_requirement_id' => $link->norm_requirement_id,
                'organization_id' => $link->organization_id,
                'created_at' => $link->created_at,
                'updated_at' => $link->updated_at,
            ],
        ], 201);
    }

    /**
     * DELETE /api/questions/{questionId}/norm-requirements/{linkId}
     * Elimina una vinculación existente.
     */
    public function destroy(string $questionId, string $linkId): JsonResponse
    {
        DB::transaction(function () use ($questionId, $linkId) {
            $question = Question::findOrFail($questionId);

            $versionId = $question->checklistSection
                ->checklist_version_id;

            $version = ChecklistVersion::query()
                ->lockForUpdate()
                ->findOrFail($versionId);

            $this->ensureDraft($version);

            // El vínculo debe pertenecer a esta pregunta.
            $link = QuestionNormRequirement::query()
                ->where('question_id', $question->id)
                ->findOrFail($linkId);

            $link->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * Solo permite modificaciones en versiones draft.
     */
    private function ensureDraft(ChecklistVersion $version): void
    {
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages([
                'version' =>
                    'Solo se pueden modificar vínculos normativos de versiones en borrador.',
            ]);
        }
    }
}