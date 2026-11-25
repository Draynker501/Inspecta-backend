<?php

namespace App\Services;

use App\Models\Inspection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InspectionSummaryService
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Genera información descriptiva y avance para inspecciones
     * previamente autorizadas por el controlador o servicio.
     */
    public function summarize(Collection $inspections): array
    {
        if ($inspections->isEmpty()) {
            return [];
        }

        $organizationId = $this->tenantContext->requireId();

        // Verificar que todas las inspecciones pertenezcan
        // a la organización activa.
        foreach ($inspections as $inspection) {
            abort_unless(
                $inspection->organization_id === $organizationId,
                404
            );
        }

        $inspections->load([
            'user:id,name',

            'checklistVersion' => fn ($query) => $query
                ->where('organization_id', $organizationId),

            'checklistVersion.checklist' => fn ($query) => $query
                ->where('organization_id', $organizationId),

            'plant' => fn ($query) => $query
                ->where('organization_id', $organizationId),

            'area' => fn ($query) => $query
                ->where('organization_id', $organizationId),

            'productionLine' => fn ($query) => $query
                ->where('organization_id', $organizationId),
        ]);

        $progress = $this->calculateProgress(
            $inspections,
            $organizationId
        );

        $summaries = [];

        foreach ($inspections as $inspection) {
            $inspectionProgress = $progress[$inspection->id];

            $summaries[$inspection->id] = [
                'inspector_name' => $inspection->user?->name,

                'plant_name' => $inspection->plant?->name,

                'area_name' => $inspection->area?->name,

                'production_line_name' => $inspection
                    ->productionLine?->name,

                'checklist_name' => $inspection
                    ->checklistVersion?->checklist?->name,

                'checklist_version' => $inspection
                    ->checklistVersion?->version,

                'progress' => $inspectionProgress['percentage'],

                'answered_questions' => $inspectionProgress['answered'],

                'required_questions' => $inspectionProgress['required'],
            ];
        }

        return $summaries;
    }

    /**
     * Genera el resumen de una sola inspección.
     */
    public function summarizeOne(Inspection $inspection): array
    {
        $summaries = $this->summarize(
            new Collection([$inspection])
        );

        return $summaries[$inspection->id];
    }

    /**
     * Calcula el avance de todas las inspecciones mediante
     * dos consultas agrupadas.
     */
    private function calculateProgress(
        Collection $inspections,
        string $organizationId
    ): array {
        $inspectionIds = $inspections
            ->pluck('id')
            ->unique()
            ->values()
            ->all();

        $versionIds = $inspections
            ->pluck('checklist_version_id')
            ->unique()
            ->values()
            ->all();

        /*
        |--------------------------------------------------------------------------
        | Preguntas obligatorias por versión
        |--------------------------------------------------------------------------
        */

        $requiredByVersion = DB::table('questions as q')
            ->join('checklist_sections as s', function ($join): void {
                $join
                    ->on('s.id', '=', 'q.checklist_section_id')
                    ->on('s.organization_id', '=', 'q.organization_id');
            })
            ->where('q.organization_id', $organizationId)
            ->where('s.organization_id', $organizationId)
            ->whereIn('s.checklist_version_id', $versionIds)
            ->where('q.status', true)
            ->where('q.required', true)
            ->selectRaw(
                's.checklist_version_id, COUNT(DISTINCT q.id) as total'
            )
            ->groupBy('s.checklist_version_id')
            ->pluck('total', 's.checklist_version_id');

        /*
        |--------------------------------------------------------------------------
        | Preguntas obligatorias respondidas por inspección
        |--------------------------------------------------------------------------
        */

        $answeredByInspection = DB::table('inspection_answers as a')
            ->join('inspections as i', function ($join): void {
                $join
                    ->on('i.id', '=', 'a.inspection_id')
                    ->on('i.organization_id', '=', 'a.organization_id')
                    ->on(
                        'i.checklist_version_id',
                        '=',
                        'a.checklist_version_id'
                    );
            })
            ->join('questions as q', function ($join): void {
                $join
                    ->on('q.id', '=', 'a.question_id')
                    ->on('q.organization_id', '=', 'a.organization_id')
                    ->on(
                        'q.checklist_section_id',
                        '=',
                        'a.checklist_section_id'
                    );
            })
            ->join('checklist_sections as s', function ($join): void {
                $join
                    ->on('s.id', '=', 'q.checklist_section_id')
                    ->on('s.organization_id', '=', 'q.organization_id')
                    ->on(
                        's.checklist_version_id',
                        '=',
                        'a.checklist_version_id'
                    );
            })
            ->where('a.organization_id', $organizationId)
            ->where('i.organization_id', $organizationId)
            ->where('q.organization_id', $organizationId)
            ->where('s.organization_id', $organizationId)
            ->whereIn('a.inspection_id', $inspectionIds)
            ->where('q.status', true)
            ->where('q.required', true)
            ->whereNotNull('a.answer')
            ->where('a.answer', '!=', '')
            ->where('a.answer', '!=', '__NA__')
            ->selectRaw(
                'a.inspection_id, COUNT(DISTINCT q.id) as total'
            )
            ->groupBy('a.inspection_id')
            ->pluck('total', 'a.inspection_id');

        /*
        |--------------------------------------------------------------------------
        | Consolidación de resultados
        |--------------------------------------------------------------------------
        */

        $progress = [];

        foreach ($inspections as $inspection) {
            $required = (int) $requiredByVersion->get(
                $inspection->checklist_version_id,
                0
            );

            $answered = (int) $answeredByInspection->get(
                $inspection->id,
                0
            );

            $percentage = $required > 0
                ? round(($answered / $required) * 100, 2)
                : null;

            $progress[$inspection->id] = [
                'required' => $required,
                'answered' => $answered,
                'percentage' => $percentage,
            ];
        }

        return $progress;
    }
}
