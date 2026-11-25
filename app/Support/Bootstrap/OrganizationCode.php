<?php

namespace App\Support\Bootstrap;

use InvalidArgumentException;

/**
 * Reglas de codigo de organizacion compartidas por los bootstraps.
 *
 * El codigo se normaliza antes de validarse y persistirse. INSPECTA-OP queda
 * reservado exclusivamente para la organizacion operadora.
 */
final class OrganizationCode
{
    public const OPERATOR = 'INSPECTA-OP';

    public const PATTERN = '/^[A-Z][A-Z0-9_-]{1,49}$/';

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Valida un codigo de organizacion cliente.
     *
     * @throws InvalidArgumentException
     */
    public static function assertClientCode(string $code): void
    {
        if (! preg_match(self::PATTERN, $code)) {
            throw new InvalidArgumentException('El codigo debe empezar por letra mayuscula y usar solo A-Z, 0-9, guion o guion bajo (2-50 caracteres).');
        }

        if ($code === self::OPERATOR) {
            throw new InvalidArgumentException('El codigo INSPECTA-OP esta reservado para la organizacion operadora.');
        }
    }

    /**
     * Valida el codigo de la organizacion operadora.
     *
     * @throws InvalidArgumentException
     */
    public static function assertOperatorCode(string $code): void
    {
        if ($code !== self::OPERATOR) {
            throw new InvalidArgumentException('El codigo de la organizacion operadora debe ser INSPECTA-OP.');
        }
    }
}
