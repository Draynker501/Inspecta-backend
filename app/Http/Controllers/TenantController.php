<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Clase base para controladores de dominio (opción D: HasMiddleware).
 *
 * Exige que exista un contexto organizacional activo antes de ejecutar
 * cualquier acción del controlador. El middleware se declara estáticamente
 * mediante HasMiddleware, por lo que Laravel lo agrega al pipeline de la
 * ruta ANTES de instanciar el controlador, garantizando fail-closed incluso
 * si la ruta se declara fuera del grupo api.tenant.
 */
abstract class TenantController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('tenant.require'),
        ];
    }

    public function __construct()
    {
        // Intencionalmente vacío: no se usa requireId() aquí porque el
        // controlador se instancia tras ejecutar sus middlewares estáticos.
        // El contexto se garantiza por el middleware tenant.require.
    }
}
