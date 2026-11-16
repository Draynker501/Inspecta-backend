<?php

namespace App\Support\Ehs;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Las escrituras EHS deben adquirir primero el mismo mutex organizacional.
 * Las lecturas históricas son locking reads para evitar snapshots obsoletos en MariaDB.
 */
class NormWriteGuard
{
    public function lockOrganization(string $organizationId): void
    {
        DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->firstOrFail();
    }

    public function assertNotHistorical(string $organizationId, ?string $normId = null, ?string $requirementId = null): void
    {
        $query = DB::table('question_norm_requirement as links')
            ->join('norm_requirements as requirements', 'requirements.id', '=', 'links.norm_requirement_id')
            ->join('questions as questions', 'questions.id', '=', 'links.question_id')
            ->join('checklist_sections as sections', 'sections.id', '=', 'questions.checklist_section_id')
            ->join('checklist_versions as versions', 'versions.id', '=', 'sections.checklist_version_id')
            ->where('requirements.organization_id', $organizationId)
            ->whereNotNull('versions.published_at');
        if ($normId !== null) {
            $query->where('requirements.norm_id', $normId);
        }
        if ($requirementId !== null) {
            $query->where('requirements.id', $requirementId);
        }
        if ($query->lockForUpdate()->select('links.id')->first() !== null) {
            throw ValidationException::withMessages(['history' => 'El recurso está referenciado por una versión publicada alguna vez.']);
        }
    }
}
