<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\OrganizationController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistVersionController;
use App\Http\Controllers\ChecklistSectionController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\NormController;
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

Route::middleware('api.tenant')->group(function () {
    Route::get('checklists', [ChecklistController::class, 'index']);
    Route::post('checklists', [ChecklistController::class, 'store']);

    Route::get('checklists/{id}', [ChecklistController::class, 'show']);
    Route::put('checklists/{id}', [ChecklistController::class, 'update']);
    Route::patch('checklists/{id}/status', [ChecklistController::class, 'updateStatus']);
});

Route::middleware('api.tenant')->group(function () {
    Route::get('checklists/{id}/versions', [ChecklistVersionController::class, 'index']);
    Route::post('checklists/{id}/versions', [ChecklistVersionController::class, 'store']);

    Route::get('checklists/{id}/versions/{version_id}', [ChecklistVersionController::class, 'show']);
    Route::put('checklists/{id}/versions/{version_id}', [ChecklistVersionController::class, 'update']);
    Route::patch('checklists/{id}/versions/{version_id}/status', [ChecklistVersionController::class, 'updateStatus']);
});

Route::middleware('api.tenant')->group(function () {
    Route::get('versions/{versionId}/sections', [ChecklistSectionController::class, 'index']);
    Route::post('versions/{versionId}/sections', [ChecklistSectionController::class, 'store']);

    Route::get('sections/{id}', [ChecklistSectionController::class, 'show']);
    Route::put('sections/{id}', [ChecklistSectionController::class, 'update']);
});

Route::middleware('api.tenant')->group(function () {
    Route::get('sections/{sectionId}/questions', [QuestionController::class, 'index']);
    Route::post('sections/{sectionId}/questions', [QuestionController::class, 'store']);

    Route::get('questions/{id}', [QuestionController::class, 'show']);
    Route::put('questions/{id}', [QuestionController::class, 'update']);
    Route::patch('questions/{id}/status', [QuestionController::class, 'updateStatus']);
});

Route::middleware('api.tenant')->group(function () {
    Route::get('norms', [NormController::class, 'index']);
    Route::post('norms', [NormController::class, 'store']);

    Route::get('norms/{id}', [NormController::class, 'show']);
    Route::put('norms/{id}', [NormController::class, 'update']);
    Route::patch('norms/{id}/status', [NormController::class, 'updateStatus']);
});