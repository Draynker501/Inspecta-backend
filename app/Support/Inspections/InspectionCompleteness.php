<?php

namespace App\Support\Inspections;

use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Models\Question;
use Illuminate\Validation\ValidationException;

final class InspectionCompleteness
{
    /**
     * Solo lectura. El caller debe bloquear la inspección en su transacción
     * antes de invocar este método y coordinar los escritores de respuestas.
     *
     * @throws ValidationException
     */
    public static function assertComplete(Inspection $inspection): void
    {
        $questions = Question::query()
            ->select('questions.*')
            ->join('checklist_sections', function ($join): void {
                $join->on('checklist_sections.id', '=', 'questions.checklist_section_id')
                    ->on('checklist_sections.organization_id', '=', 'questions.organization_id');
            })
            ->where('questions.organization_id', $inspection->organization_id)
            ->where('checklist_sections.checklist_version_id', $inspection->checklist_version_id)
            ->where('questions.status', true)
            ->get();

        if ($questions->isEmpty()) {
            self::incomplete();
        }

        $answers = InspectionAnswer::query()
            ->where('organization_id', $inspection->organization_id)
            ->where('inspection_id', $inspection->id)
            ->get()
            ->keyBy('question_id');

        foreach ($questions as $question) {
            $answer = $answers->get($question->id);

            if ($answer === null && ! $question->required) {
                continue;
            }

            if ($answer === null || $answer->answer === '__NA__') {
                self::incomplete();
            }

            try {
                InspectionAnswerValidator::validate($question, $answer->answer);
            } catch (ValidationException) {
                self::incomplete();
            }
        }
    }

    private static function incomplete(): never
    {
        throw ValidationException::withMessages([
            'answers' => 'La inspección no tiene respuestas completas y válidas.',
        ]);
    }
}
