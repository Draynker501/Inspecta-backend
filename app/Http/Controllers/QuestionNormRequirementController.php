<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\Question;
use App\Models\QuestionNormRequirement;
use App\Support\Audit\AuditLogger;
use App\Support\Ehs\NormWriteGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

// Adaptado de origin/api e26aac3: acceso contextual, mutex y auditoría.
class QuestionNormRequirementController extends TenantController
{
    public function index(Request $request, string $questionId): JsonResponse
    {
        Gate::authorize('viewAny', Checklist::class);
        // Opción B (PWA-FUNC-01.3.1): este endpoint expone contenido normativo
        // directo, por lo que exige `norms.view` de forma independiente al
        // permiso operativo de checklist.
        Gate::authorize('viewAny', Norm::class);
        $org = app(TenantContext::class)->requireId();
        [$question, $checklist] = $this->resolveQuestion($org, $questionId);
        Gate::authorize('view', $checklist);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $links = DB::table('question_norm_requirement as links')
            ->join('norm_requirements as requirements', 'requirements.id', '=', 'links.norm_requirement_id')
            ->join('norms as norms', 'norms.id', '=', 'requirements.norm_id')
            ->where('links.organization_id', $org)->where('requirements.organization_id', $org)
            ->where('norms.organization_id', $org)->where('links.question_id', $question->id)
            ->orderBy('requirements.code')->orderBy('requirements.id')
            ->select('links.id as link_id', 'requirements.id', 'requirements.code', 'requirements.section', 'requirements.description')
            ->paginate($data['per_page'] ?? 15, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['data' => $links->items(), 'meta' => [
            'current_page' => $links->currentPage(), 'last_page' => $links->lastPage(),
            'per_page' => $links->perPage(), 'total' => $links->total(),
        ]]);
    }

    public function store(Request $request, string $questionId): JsonResponse
    {
        Gate::authorize('create', Checklist::class);
        Gate::authorize('viewAny', Norm::class);
        $org = app(TenantContext::class)->requireId();
        $link = DB::transaction(function () use ($request, $questionId, $org) {
            app(NormWriteGuard::class)->lockOrganization($org);
            [$question, $checklist, $version] = $this->resolveQuestion($org, $questionId, true);
            Gate::authorize('update', $checklist);
            $this->assertDraft($version);
            if (array_diff(array_keys($request->all()), ['norm_requirement_id'])) {
                throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
            }
            $data = $request->validate(['norm_requirement_id' => ['required', 'uuid']]);
            $candidate = NormRequirement::where('organization_id', $org)->findOrFail($data['norm_requirement_id']);
            $norm = Norm::where('organization_id', $org)->lockForUpdate()->findOrFail($candidate->norm_id);
            Gate::authorize('view', $norm);
            $requirement = NormRequirement::where('organization_id', $org)->where('norm_id', $norm->id)->lockForUpdate()->findOrFail($candidate->id);
            if (! $norm->status) {
                throw ValidationException::withMessages(['norm_requirement_id' => 'No se pueden vincular requisitos de normas inactivas.']);
            }
            if (QuestionNormRequirement::where('organization_id', $org)->where('question_id', $question->id)
                ->where('norm_requirement_id', $requirement->id)->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['norm_requirement_id' => 'El requisito ya está vinculado.']);
            }
            $link = QuestionNormRequirement::create(['organization_id' => $org, 'question_id' => $question->id, 'norm_requirement_id' => $requirement->id]);
            app(AuditLogger::class)->record('question_norm_requirement.created', $link, [], $link->only(['question_id', 'norm_requirement_id']));

            return $link;
        });

        return response()->json(['data' => ['link_id' => $link->id, 'question_id' => $link->question_id, 'norm_requirement_id' => $link->norm_requirement_id]], 201);
    }

    public function destroy(string $questionId, string $linkId): JsonResponse
    {
        Gate::authorize('create', Checklist::class);
        $org = app(TenantContext::class)->requireId();
        DB::transaction(function () use ($questionId, $linkId, $org) {
            app(NormWriteGuard::class)->lockOrganization($org);
            [$question, $checklist, $version] = $this->resolveQuestion($org, $questionId, true);
            Gate::authorize('update', $checklist);
            $this->assertDraft($version);
            $link = QuestionNormRequirement::where('organization_id', $org)->where('question_id', $question->id)->lockForUpdate()->findOrFail($linkId);
            app(AuditLogger::class)->record('question_norm_requirement.deleted', $link, $link->only(['question_id', 'norm_requirement_id']), []);
            $link->delete();
        });

        return response()->json(null, 204);
    }

    private function resolveQuestion(string $org, string $questionId, bool $lock = false): array
    {
        $question = Question::where('organization_id', $org)->findOrFail($questionId);
        $section = ChecklistSection::where('organization_id', $org)->findOrFail($question->checklist_section_id);
        $candidate = ChecklistVersion::where('organization_id', $org)->findOrFail($section->checklist_version_id);
        $query = Checklist::where('organization_id', $org);
        if ($lock) {
            $query->lockForUpdate();
        }
        $checklist = $query->findOrFail($candidate->checklist_id);
        $versions = ChecklistVersion::where('organization_id', $org)->where('checklist_id', $checklist->id);
        if ($lock) {
            $versions->lockForUpdate();
        }
        $version = $versions->findOrFail($candidate->id);

        return [$question, $checklist, $version];
    }

    private function assertDraft(ChecklistVersion $version): void
    {
        if ($version->status !== 'draft' || $version->published_at !== null) {
            throw ValidationException::withMessages(['version' => 'Solo se pueden modificar borradores nunca publicados.']);
        }
    }
}
