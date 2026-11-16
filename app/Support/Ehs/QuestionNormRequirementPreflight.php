<?php

namespace App\Support\Ehs;

use Illuminate\Database\Connection;
use RuntimeException;

final class QuestionNormRequirementPreflight
{
    /**
     * Validación de datos previa al DDL. No modifica ni repara filas históricas.
     */
    public static function assertValid(Connection $db): void
    {
        $invalid = $db->table('question_norm_requirement as pivot')
            ->leftJoin('questions as q', function ($join): void {
                $join->on('q.id', '=', 'pivot.question_id')
                    ->on('q.organization_id', '=', 'pivot.organization_id');
            })
            ->leftJoin('norm_requirements as r', function ($join): void {
                $join->on('r.id', '=', 'pivot.norm_requirement_id')
                    ->on('r.organization_id', '=', 'pivot.organization_id');
            })
            ->where(function ($query): void {
                $query->whereNull('pivot.organization_id')
                    ->orWhereNull('q.id')
                    ->orWhereNull('r.id');
            })->exists();

        if ($invalid) {
            throw new RuntimeException('El pivote EHS contiene referencias huérfanas o de otra organización; no se ejecutó DDL.');
        }
    }
}
