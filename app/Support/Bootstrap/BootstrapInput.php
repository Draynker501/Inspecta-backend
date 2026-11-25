<?php

namespace App\Support\Bootstrap;

use InvalidArgumentException;

/**
 * Validaciones de entrada compartidas por comandos y servicios.
 *
 * Los servicios de bootstrap son la autoridad: aunque un llamador interno
 * invoque el servicio directamente, las mismas reglas se aplican aqui.
 */
final class BootstrapInput
{
    public const MIN_PASSWORD_LENGTH = 12;

    public const MAX_NAME = 150;

    public const MAX_EMAIL = 150;

    public const MAX_SHORT_TEXT = 50;

    public static function organizationName(mixed $value): string
    {
        $name = trim((string) $value);

        if ($name === '') {
            throw new InvalidArgumentException('El nombre de la organizacion es obligatorio.');
        }

        if (mb_strlen($name) > self::MAX_NAME) {
            throw new InvalidArgumentException('El nombre de la organizacion supera '.self::MAX_NAME.' caracteres.');
        }

        return $name;
    }

    public static function personName(mixed $value): string
    {
        $name = trim((string) $value);

        if ($name === '') {
            throw new InvalidArgumentException('El nombre del administrador es obligatorio.');
        }

        if (mb_strlen($name) > self::MAX_NAME) {
            throw new InvalidArgumentException('El nombre del administrador supera '.self::MAX_NAME.' caracteres.');
        }

        return $name;
    }

    /**
     * Normaliza (trim + minusculas) y valida un correo.
     */
    public static function email(mixed $value, bool $required, string $label): ?string
    {
        $email = strtolower(trim((string) $value));

        if ($email === '') {
            if ($required) {
                throw new InvalidArgumentException("{$label} es obligatorio.");
            }

            return null;
        }

        if (mb_strlen($email) > self::MAX_EMAIL || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("{$label} no es valido.");
        }

        return $email;
    }

    public static function optionalString(mixed $value, ?int $max, string $label): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if ($max !== null && mb_strlen($text) > $max) {
            throw new InvalidArgumentException("{$label} supera {$max} caracteres.");
        }

        return $text;
    }

    public static function password(mixed $value): string
    {
        $password = is_string($value) ? $value : '';

        if ($password === '' || trim($password) === '') {
            throw new InvalidArgumentException('La contrasena no puede estar vacia.');
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('La contrasena debe tener al menos '.self::MIN_PASSWORD_LENGTH.' caracteres.');
        }

        return $password;
    }
}
