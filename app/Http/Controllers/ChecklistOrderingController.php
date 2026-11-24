<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Organization;
use App\Services\ChecklistOrderingService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChecklistOrderingController extends TenantController
{
    /**
     * Reordena las secciones de una versión.
     */
    public function reorderSections(
        Request $request,
        string $versionId,
        ChecklistOrderingService $ordering
    ): JsonResponse {
        Gate::authorize('create', Checklist::class);

        $organizationId = app(TenantContext::class)->requireId();

        $sections = DB::transaction(function () use (
            $request,
            $versionId,
            $organizationId,
            $ordering
        ) {
            // Mantener el orden de bloqueo existente.
            Organization::query()
                ->lockForUpdate()
                ->findOrFail($organizationId);

            $candidate = ChecklistVersion::query()
                ->where('organization_id', $organizationId)
                ->findOrFail($versionId);

            $checklist = Checklist::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->findOrFail($candidate->checklist_id);

            Gate::authorize('update', $checklist);

            $version = ChecklistVersion::query()
                ->where('organization_id', $organizationId)
                ->where('checklist_id', $checklist->id)
                ->lockForUpdate()
                ->findOrFail($versionId);

            $this->ensureDraft($version);

            $ids = $this->validateIds(
                $request,
                'section_ids'
            );

            return $ordering->reorderSections(
                $version,
                $ids
            );
        });

        return response()->json([
            'data' => $sections,
        ]);
    }

    /**
     * Reordena las preguntas de una sección.
     */
    public function reorderQuestions(
        Request $request,
        string $sectionId,
        ChecklistOrderingService $ordering
    ): JsonResponse {
        Gate::authorize('create', Checklist::class);

        $organizationId = app(TenantContext::class)->requireId();

        $questions = DB::transaction(function () use (
            $request,
            $sectionId,
            $organizationId,
            $ordering
        ) {
            Organization::query()
                ->lockForUpdate()
                ->findOrFail($organizationId);

            $candidateSection = ChecklistSection::query()
                ->where('organization_id', $organizationId)
                ->findOrFail($sectionId);

            $candidateVersion = ChecklistVersion::query()
                ->where('organization_id', $organizationId)
                ->findOrFail(
                    $candidateSection->checklist_version_id
                );

            $checklist = Checklist::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->findOrFail($candidateVersion->checklist_id);

            Gate::authorize('update', $checklist);

            $version = ChecklistVersion::query()
                ->where('organization_id', $organizationId)
                ->where('checklist_id', $checklist->id)
                ->lockForUpdate()
                ->findOrFail($candidateVersion->id);

            $section = ChecklistSection::query()
                ->where('organization_id', $organizationId)
                ->where('checklist_version_id', $version->id)
                ->lockForUpdate()
                ->findOrFail($sectionId);

            $this->ensureDraft($version);

            $ids = $this->validateIds(
                $request,
                'question_ids'
            );

            return $ordering->reorderQuestions(
                $version,
                $section,
                $ids
            );
        });

        return response()->json([
            'data' => $questions,
        ]);
    }

    /**
     * Valida la estructura del cuerpo de la solicitud.
     */
    private function validateIds(
        Request $request,
        string $field
    ): array {
        if (
            ! $request->isJson()
            || $request->query() !== []
            || ! is_object(json_decode($request->getContent()))
        ) {
            throw ValidationException::withMessages([
                'fields' => 'Se requiere un objeto JSON sin parámetros de consulta.',
            ]);
        }

        if (
            array_diff(
                array_keys($request->all()),
                [$field]
            ) !== []
        ) {
            throw ValidationException::withMessages([
                'fields' => 'La solicitud contiene campos no permitidos.',
            ]);
        }

        $validated = $request->validate([
            $field => [
                'present',
                'array',
                'list',
            ],
            $field.'.*' => [
                'required',
                'string',
                'uuid',
                'distinct:strict',
            ],
        ]);

        return $validated[$field];
    }

    /**
     * Valida que la versión pueda modificarse.
     */
    private function ensureDraft(
        ChecklistVersion $version
    ): void {
        if (
            $version->status !== 'draft'
            || $version->published_at !== null
        ) {
            throw ValidationException::withMessages([
                'version' => 'Solo se pueden modificar versiones en borrador nunca publicadas.',
            ]);
        }
    }
}
