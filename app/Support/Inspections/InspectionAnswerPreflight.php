<?php

namespace App\Support\Inspections;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Preflight de datos para la integridad de `inspection_answers`.
 *
 * Solo lee y rechaza. No repara, completa ni elimina filas: una respuesta
 * huérfana, de otra organización o vinculada a una pregunta de otra versión
 * requiere intervención supervisada.
 */
final class InspectionAnswerPreflight
{
    /**
     * @throws RuntimeException
     */
    public static function assertValid(Connection $db): void
    {
        $inconsistent = $db->table('inspection_answers as a')
            ->leftJoin('inspections as i', function ($join): void {
                $join->on('i.id', '=', 'a.inspection_id')
                    ->on('i.organization_id', '=', 'a.organization_id');
            })
            ->leftJoin('questions as q', function ($join): void {
                $join->on('q.id', '=', 'a.question_id')
                    ->on('q.organization_id', '=', 'a.organization_id');
            })
            ->leftJoin('checklist_sections as s', function ($join): void {
                $join->on('s.id', '=', 'q.checklist_section_id')
                    ->on('s.organization_id', '=', 'q.organization_id');
            })
            ->where(function ($query): void {
                $query->whereNull('i.id')
                    ->orWhereNull('q.id')
                    ->orWhereNull('s.id')
                    ->orWhereColumn('s.checklist_version_id', '<>', 'i.checklist_version_id');
            })
            ->exists();

        if ($inconsistent) {
            throw new RuntimeException(
                'INS-02: hay respuestas huérfanas, de otra organización o de una versión distinta; no se ejecutó DDL.'
            );
        }

        $duplicates = $db->table('inspection_answers')
            ->select('inspection_id', 'question_id')
            ->groupBy('inspection_id', 'question_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'INS-02: hay respuestas duplicadas por inspección y pregunta; no se ejecutó DDL.'
            );
        }
    }
}
