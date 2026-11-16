<?php

use App\Http\Controllers\AreaController;
use App\Http\Controllers\AreaStoreController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\OrganizationController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistSectionController;
use App\Http\Controllers\ChecklistVersionController;
use App\Http\Controllers\LocationHierarchyController;
use App\Http\Controllers\NormController;
use App\Http\Controllers\NormRequirementController;
use App\Http\Controllers\PlantController;
use App\Http\Controllers\ProductionLineController;
use App\Http\Controllers\ProductionLineStoreController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\QuestionNormRequirementController;
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
Route::post('plants', [PlantController::class, 'store'])->middleware('api.tenant');
Route::get('plants/{plantId}', [PlantController::class, 'show'])->middleware('api.tenant');
Route::get('plants/{plantId}/areas', [PlantController::class, 'areas'])->middleware('api.tenant');
Route::post('plants/{plantId}/areas', [AreaStoreController::class, 'store'])->middleware('api.tenant');
Route::get('areas/{areaId}', [AreaController::class, 'show'])->middleware('api.tenant');
Route::get('areas/{areaId}/lines', [AreaController::class, 'lines'])->middleware('api.tenant');
Route::post('areas/{areaId}/lines', [ProductionLineStoreController::class, 'store'])->middleware('api.tenant');
Route::get('production-lines/{productionLineId}', [ProductionLineController::class, 'show'])->middleware('api.tenant');
Route::get('locations/hierarchy', [LocationHierarchyController::class, 'index'])->middleware('api.tenant');
Route::get('checklists', [ChecklistController::class, 'index'])->middleware('api.tenant');
Route::post('checklists', [ChecklistController::class, 'store'])->middleware('api.tenant');
Route::get('checklists/{checklistId}', [ChecklistController::class, 'show'])->middleware('api.tenant');
Route::get('checklists/{checklistId}/versions', [ChecklistVersionController::class, 'index'])->middleware('api.tenant');
Route::post('checklists/{checklistId}/versions', [ChecklistVersionController::class, 'store'])->middleware('api.tenant');
Route::patch('checklists/{checklistId}/versions/{versionId}', [ChecklistVersionController::class, 'updateStatus'])->middleware('api.tenant');
Route::get('versions/{versionId}/sections', [ChecklistSectionController::class, 'index'])->middleware('api.tenant');
Route::post('versions/{versionId}/sections', [ChecklistSectionController::class, 'store'])->middleware('api.tenant');
Route::get('sections/{sectionId}/questions', [QuestionController::class, 'index'])->middleware('api.tenant');
Route::post('sections/{sectionId}/questions', [QuestionController::class, 'store'])->middleware('api.tenant');

Route::get('norms', [NormController::class, 'index'])->middleware('api.tenant');
Route::get('norms/{normId}', [NormController::class, 'show'])->middleware('api.tenant');
Route::get('norms/{normId}/requirements', [NormRequirementController::class, 'index'])->middleware('api.tenant');
Route::get('requirements/{requirementId}', [NormRequirementController::class, 'show'])->middleware('api.tenant');

Route::put('checklists/{checklistId}', [ChecklistController::class, 'update'])->middleware('api.tenant');
Route::patch('checklists/{checklistId}', [ChecklistController::class, 'updateStatus'])->middleware('api.tenant');
Route::get('checklists/{checklistId}/active-version', [ChecklistController::class, 'activeVersion'])->middleware('api.tenant');
Route::get('checklists/{checklistId}/versions/{versionId}', [ChecklistVersionController::class, 'show'])->middleware('api.tenant');
Route::put('checklists/{checklistId}/versions/{versionId}', [ChecklistVersionController::class, 'update'])->middleware('api.tenant');
Route::put('sections/{sectionId}', [ChecklistSectionController::class, 'update'])->middleware('api.tenant');
Route::get('sections/{sectionId}', [ChecklistSectionController::class, 'show'])->middleware('api.tenant');
Route::put('questions/{questionId}', [QuestionController::class, 'update'])->middleware('api.tenant');
Route::get('questions/{questionId}', [QuestionController::class, 'show'])->middleware('api.tenant');
Route::post('norms', [NormController::class, 'store'])->middleware('api.tenant');
Route::put('norms/{normId}', [NormController::class, 'update'])->middleware('api.tenant');
Route::patch('norms/{normId}', [NormController::class, 'updateStatus'])->middleware('api.tenant');
Route::post('norms/{normId}/requirements', [NormRequirementController::class, 'store'])->middleware('api.tenant');
Route::put('requirements/{requirementId}', [NormRequirementController::class, 'update'])->middleware('api.tenant');
Route::get('questions/{questionId}/norm-requirements', [QuestionNormRequirementController::class, 'index'])->middleware('api.tenant');
Route::post('questions/{questionId}/norm-requirements', [QuestionNormRequirementController::class, 'store'])->middleware('api.tenant');
Route::delete('questions/{questionId}/norm-requirements/{linkId}', [QuestionNormRequirementController::class, 'destroy'])->middleware('api.tenant');
