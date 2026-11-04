<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Garantiza que la evaluación de membresía activa se centralice en
 * OrganizationMembership::findActive().
 *
 * Lista permitida (con motivo):
 * - app/Models/OrganizationMembership.php: define findActive() y los scopes.
 * - app/Support/Tenancy/MembershipBackfill.php: escribe membresías vía DB::table;
 *   no construye consultas de lectura filtrando por estado activo con el modelo.
 */
class MembershipSingleSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_hay_consultas_inline_de_membresia_activa_fuera_del_punto_central(): void
    {
        $allowed = [
            'app/Models/OrganizationMembership.php',
            'app/Support/Tenancy/MembershipBackfill.php',
        ];

        $violations = [];

        foreach (glob(base_path('app/**/*.php'), GLOB_BRACE) as $file) {
            $relative = str_replace(base_path().'/', '', $file);

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if (str_contains($contents, 'OrganizationMembership') && str_contains($contents, 'STATUS_ACTIVE')) {
                $violations[] = $relative;
            }
        }

        $this->assertSame([], $violations, 'Consultas inline de membresía activa fuera del punto central: '.implode(', ', $violations));
    }
}
