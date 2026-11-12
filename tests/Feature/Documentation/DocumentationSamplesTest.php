<?php

namespace Tests\Feature\Documentation;

use Tests\TestCase;

class DocumentationSamplesTest extends TestCase
{
    private const ALLOWED_EMAIL_DOMAINS = ['example.com', 'example.test', 'inspecta-demo.com'];

    public function test_todos_los_correos_pertenecen_a_dominios_permitidos(): void
    {
        $files = array_merge(
            ['openapi.yaml'],
            $this->docFiles()
        );

        $violations = [];

        foreach ($files as $relative) {
            $full = base_path($relative);

            if (! is_file($full)) {
                continue;
            }

            $content = (string) file_get_contents($full);

            preg_match_all(
                '/[A-Za-z0-9._%+\-]+@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/',
                $content,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $domain = strtolower($match[1]);

                if (! in_array($domain, self::ALLOWED_EMAIL_DOMAINS, true)) {
                    $violations[] = "{$relative}: {$match[0]}";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Correos con dominio no permitido: '.implode(', ', $violations)
        );
    }

    public function test_no_hay_contrasenas_de_ejemplo_distintas_del_marcador(): void
    {
        $content = (string) file_get_contents(base_path('openapi.yaml'));

        preg_match_all('/example:\s*(.+)$/m', $content, $matches);

        $violations = [];

        foreach ($matches[1] as $raw) {
            $value = trim($raw, " \t\"'");

            // El marcador permitido del contrato.
            if ($value === '<password>') {
                continue;
            }

            // Patrón de contraseña de ejemplo: un único token (sin espacios,
            // barras, comas, puntos ni guiones bajos) con al menos una
            // mayúscula, una minúscula, un dígito y un carácter especial de
            // puntuación, longitud >= 8.
            if (preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[!@#$%^&*()\-+=\[\]{}|;:\'\"",<>?])[A-Za-z0-9!@#$%^&*()\-+=\[\]{}|;:\'\"",<>?]{8,}$/', $value)) {
                $violations[] = $value;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Contraseñas de ejemplo no permitidas: '.implode(', ', $violations)
        );
    }

    /**
     * @return list<string>
     */
    private function docFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('docs'))
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        return $files;
    }
}
