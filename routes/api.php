<?php

use App\Http\Controllers\Auth\AuthController;
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
    Route::post('logout', [AuthController::class, 'logout'])->middleware('api.auth');
    Route::get('me', [AuthController::class, 'me'])->middleware('api.auth');
});
