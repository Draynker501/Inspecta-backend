<?php

namespace Database\Seeders;

/**
 * Condición centralizada: los datos de demostración solo se siembran
 * en entornos local y testing.
 */
final class DemoData
{
    public static function allowed(): bool
    {
        return app()->environment(['local', 'testing']);
    }
}
