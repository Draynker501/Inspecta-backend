<?php

namespace App\Support\Inspections;

use App\Models\Question;
use Illuminate\Validation\ValidationException;

/**
 * Validación reutilizable de respuestas según el tipo de pregunta.
 *
 * El valor se almacena como string (contrato histórico `AnswerInput`).
 * Los tipos todavía no soportados en respuestas online (firma) se rechazan
 * de forma explícita en lugar de inferir un formato no aprobado por EHS.
 */
final class InspectionAnswerValidator
{
    /**
     * Tipos admitidos en INS-02.
     *
     * @var list<string>
     */
    public const SUPPORTED_TYPES = ['boolean', 'text', 'number', 'date', 'multiple_choice'];

    public static function supports(string $answerType): bool
    {
        return in_array($answerType, self::SUPPORTED_TYPES, true);
    }

    /**
     * @throws ValidationException
     */
    public static function assertSupported(Question $question): void
    {
        if (! self::supports($question->answer_type)) {
            throw ValidationException::withMessages([
                'answer' => 'El tipo de pregunta todavía no se admite en respuestas online.',
            ]);
        }
    }

    /**
     * Valida el valor recibido contra el tipo y las opciones configuradas.
     *
     * @throws ValidationException
     */
    public static function validate(Question $question, mixed $value): void
    {
        self::assertSupported($question);

        if (! is_string($value) || ! self::isValidValue($question, $value)) {
            throw ValidationException::withMessages([
                'answer' => self::message($question->answer_type),
            ]);
        }
    }

    public static function message(string $answerType): string
    {
        return match ($answerType) {
            'boolean' => 'La respuesta booleana debe ser "true" o "false".',
            'text' => 'La respuesta de texto debe ser una cadena no vacía, no compuesta solo por espacios, de hasta 65535 bytes.',
            'number' => 'La respuesta numérica debe ser un decimal sin exponentes ni comas, de hasta 64 caracteres.',
            'date' => 'La respuesta de fecha debe tener el formato YYYY-MM-DD y ser una fecha real.',
            'multiple_choice' => 'La respuesta debe coincidir exactamente con una de las opciones configuradas.',
            default => 'El tipo de pregunta todavía no se admite en respuestas online.',
        };
    }

    private static function isValidValue(Question $question, string $value): bool
    {
        return match ($question->answer_type) {
            'boolean' => in_array($value, ['true', 'false'], true),
            'text' => strlen($value) <= 65535 && ! self::isBlank($value),
            'number' => self::isDecimal($value),
            'date' => self::isDate($value),
            'multiple_choice' => self::isConfiguredOption($question, $value),
            default => false,
        };
    }

    /**
     * Una respuesta de texto no puede ser vacía ni estar compuesta solo por
     * espacios en blanco (incluidos los separadores Unicode).
     *
     * La comprobación no recorta el valor: cualquier texto con al menos un
     * carácter significativo se conserva exactamente como llegó.
     */
    private static function isBlank(string $value): bool
    {
        return preg_match('/^[\s\p{Z}]*$/u', $value) === 1;
    }

    /**
     * Decimal simple: signo opcional, dígitos y punto decimal opcional.
     * Sin exponente, coma, separadores, INF ni NAN.
     */
    private static function isDecimal(string $value): bool
    {
        return strlen($value) <= 64
            && preg_match('/^-?\d+(?:\.\d+)?$/D', $value) === 1;
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $matches) !== 1) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    private static function isConfiguredOption(Question $question, string $value): bool
    {
        $options = $question->options;

        if (! is_array($options)) {
            return false;
        }

        foreach ($options as $option) {
            if (is_array($option) && array_key_exists('value', $option)
                && is_string($option['value']) && $option['value'] === $value) {
                return true;
            }
        }

        return false;
    }
}
