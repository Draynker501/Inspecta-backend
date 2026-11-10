<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteSurfaceTest extends TestCase
{
    /**
     * Superficie de rutas permitida para la API de INSPECTA.
     *
     * Excepción conocida (pendiente de revisión para producción):
     * la URI "/" sirve la vista "welcome" y no es un endpoint de API.
     * Se mantiene por ahora para compatibilidad con el esqueleto de Laravel,
     * pero debe revisarse antes de desplegar a producción.
     */
    public function test_todas_las_rutas_registradas_pertenecen_a_la_superficie_permitida(): void
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        $unexpected = array_values(array_filter(
            $uris,
            static fn (string $uri): bool => ! (
                str_starts_with($uri, 'api/')
                || $uri === 'up'
                || $uri === '/'
            )
        ));

        $this->assertSame(
            [],
            $unexpected,
            'Rutas fuera de la superficie permitida: '.implode(', ', $unexpected)
        );
    }

    public function test_no_estan_registradas_las_rutas_desactivadas(): void
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        $this->assertNotContains('sanctum/csrf-cookie', $uris);

        foreach ($uris as $uri) {
            $this->assertStringStartsNotWith(
                'storage/',
                $uri,
                "Ruta de servido de archivos registrada: {$uri}"
            );
        }
    }
}
