<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\OrganizationController;
use App\Http\Controllers\PlantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de API
|--------------------------------------------------------------------------
|
| Aquí se registran las rutas de la API de INSPECTA. Todas las rutas
| de este grupo reciben el prefijo "/api" y requieren autenticación
| salvo las explícitamente indicadas.
*/

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    // Logout solo exige autenticación: un usuario debe poder revocar su token
    // aunque su organización haya quedado inactiva.
    Route::post('logout', [AuthController::class, 'logout'])->middleware('api.auth');
    // Identidad global: no requiere contexto organizacional.
    Route::get('me', [AuthController::class, 'me'])->middleware('api.auth');
    // Listado de organizaciones del usuario autenticado: no requiere tenant.
    Route::get('organizations', [OrganizationController::class, 'index'])->middleware('api.auth');
});

Route::get('plants', [PlantController::class, 'index'])->middleware('api.tenant');
