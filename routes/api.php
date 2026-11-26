<?php

use App\Http\Controllers\Administration\RoleController;
use App\Http\Controllers\Administration\UserController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\AreaStoreController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\OrganizationController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistOrderingController;
use App\Http\Controllers\ChecklistSectionController;
use App\Http\Controllers\ChecklistVersionController;
use App\Http\Controllers\CorrectiveActionController;
use App\Http\Controllers\CorrectiveActionUpdateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\InspectionAnswerController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\LocationHierarchyController;
use App\Http\Controllers\NormController;
use App\Http\Controllers\NormRequirementController;
use App\Http\Controllers\PlantController;
use App\Http\Controllers\ProductionLineController;
use App\Http\Controllers\ProductionLineStoreController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\QuestionNormRequirementController;
use App\Http\Controllers\ReportController;
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
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    // Logout sin dependencia del contexto organizacional.
    Route::post('logout', [AuthController::class, 'logout'])
        ->middleware('api.auth');

    // Consulta de identidad global.
    Route::get('me', [AuthController::class, 'me'])
        ->middleware('api.auth');

    // Actualización de identidad global.
    Route::patch('me', [ProfileController::class, 'update'])
        ->middleware(['api.auth', 'throttle:profile-update']);

    // Cambio seguro de contraseña.
    Route::put('password', [ProfileController::class, 'updatePassword'])
        ->middleware(['api.auth', 'throttle:profile-password']);

    // Información organizacional del usuario autenticado.
    Route::get('me/membership', [ProfileController::class, 'membership'])
        ->middleware(['api.tenant', 'tenant.require']);

    // Capacidades de la organización activa.
    Route::get('capabilities', [AuthController::class, 'capabilities'])
        ->middleware(['api.tenant', 'tenant.require']);

    // Organizaciones accesibles para el usuario autenticado.
    Route::get('organizations', [OrganizationController::class, 'index'])
        ->middleware('api.auth');
});

Route::get('dashboard', [DashboardController::class, 'index'])->middleware('api.tenant');
Route::get('reports/summary', [ReportController::class, 'summary'])->middleware('api.tenant');
// Administración de roles y permisos.
Route::get('roles', [RoleController::class, 'index'])->middleware('api.tenant');
Route::post('roles', [RoleController::class, 'store'])->middleware('api.tenant');
Route::get('roles/{roleId}', [RoleController::class, 'show'])->middleware('api.tenant');
Route::patch('roles/{roleId}', [RoleController::class, 'update'])->middleware('api.tenant');
Route::patch('roles/{roleId}/status', [RoleController::class, 'updateStatus'])->middleware('api.tenant');
Route::put('roles/{roleId}/permissions', [RoleController::class, 'replacePermissions'])->middleware('api.tenant');
Route::get('permissions', [RoleController::class, 'permissions'])->middleware('api.tenant');
// Administración de usuarios y membresías.
Route::get('users', [UserController::class, 'index'])->middleware('api.tenant');
Route::post('users', [UserController::class, 'store'])->middleware('api.tenant');
Route::get('users/{userId}', [UserController::class, 'show'])->middleware('api.tenant');
Route::patch('users/{userId}/membership/status', [UserController::class, 'updateMembershipStatus'])->middleware('api.tenant');
Route::put('users/{userId}/roles', [UserController::class, 'replaceRoles'])->middleware('api.tenant');
Route::put('users/{userId}/locations', [UserController::class, 'replaceLocations'])->middleware('api.tenant');

// Administración de plantas.
Route::get('plants', [PlantController::class, 'index'])->middleware('api.tenant');
Route::post('plants', [PlantController::class, 'store'])->middleware('api.tenant');
Route::get('plants/{plantId}', [PlantController::class, 'show'])->middleware('api.tenant');
Route::patch('plants/{plantId}', [PlantController::class, 'update'])->middleware('api.tenant');
Route::patch('plants/{plantId}/status', [PlantController::class, 'updateStatus'])->middleware('api.tenant');

// Administración de áreas.
Route::get('plants/{plantId}/areas', [PlantController::class, 'areas'])->middleware('api.tenant');
Route::post('plants/{plantId}/areas', [AreaStoreController::class, 'store'])->middleware('api.tenant');
Route::get('areas/{areaId}', [AreaController::class, 'show'])->middleware('api.tenant');
Route::patch('areas/{areaId}', [AreaController::class, 'update'])->middleware('api.tenant');
Route::patch('areas/{areaId}/status', [AreaController::class, 'updateStatus'])->middleware('api.tenant');

// Administración de líneas de producción.
Route::get('areas/{areaId}/lines', [AreaController::class, 'lines'])->middleware('api.tenant');
Route::post('areas/{areaId}/lines', [ProductionLineStoreController::class, 'store'])->middleware('api.tenant');
Route::get('production-lines/{productionLineId}', [ProductionLineController::class, 'show'])->middleware('api.tenant');
Route::patch('production-lines/{productionLineId}', [ProductionLineController::class, 'update'])->middleware('api.tenant');
Route::patch('production-lines/{productionLineId}/status', [ProductionLineController::class, 'updateStatus'])->middleware('api.tenant');

// Consulta de la jerarquía de ubicaciones.
Route::get('locations/hierarchy', [LocationHierarchyController::class, 'index'])->middleware('api.tenant');

Route::get('inspections', [InspectionController::class, 'index'])->middleware('api.tenant');
Route::post('inspections', [InspectionController::class, 'store'])->middleware('api.tenant');
Route::get('inspections/{inspectionId}', [InspectionController::class, 'show'])->middleware('api.tenant');
Route::post('inspections/{inspectionId}/complete', [InspectionController::class, 'complete'])->middleware('api.tenant');
Route::put('inspections/{inspectionId}/status', [InspectionController::class, 'review'])->middleware('api.tenant');
Route::get('inspections/{inspectionId}/checklist', [InspectionController::class, 'checklist'])->middleware('api.tenant');
Route::get('inspections/{inspectionId}/answers', [InspectionAnswerController::class, 'index'])->middleware('api.tenant');
Route::put('inspections/{inspectionId}/answers/{questionId}', [InspectionAnswerController::class, 'upsert'])->middleware('api.tenant');
Route::delete('inspections/{inspectionId}/answers/{questionId}', [InspectionAnswerController::class, 'destroy'])->middleware('api.tenant');
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
Route::patch('versions/{versionId}/sections/reorder', [ChecklistOrderingController::class, 'reorderSections'])->middleware('api.tenant');
Route::patch('sections/{sectionId}/questions/reorder', [ChecklistOrderingController::class, 'reorderQuestions'])->middleware('api.tenant');

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
Route::patch('questions/{questionId}', [QuestionController::class, 'updateStatus'])->middleware('api.tenant');
Route::get('questions/{questionId}', [QuestionController::class, 'show'])->middleware('api.tenant');
Route::post('norms', [NormController::class, 'store'])->middleware('api.tenant');
Route::put('norms/{normId}', [NormController::class, 'update'])->middleware('api.tenant');
Route::patch('norms/{normId}', [NormController::class, 'updateStatus'])->middleware('api.tenant');
Route::post('norms/{normId}/requirements', [NormRequirementController::class, 'store'])->middleware('api.tenant');
Route::put('requirements/{requirementId}', [NormRequirementController::class, 'update'])->middleware('api.tenant');
Route::get('questions/{questionId}/norm-requirements', [QuestionNormRequirementController::class, 'index'])->middleware('api.tenant');
Route::post('questions/{questionId}/norm-requirements', [QuestionNormRequirementController::class, 'store'])->middleware('api.tenant');
Route::delete('questions/{questionId}/norm-requirements/{linkId}', [QuestionNormRequirementController::class, 'destroy'])->middleware('api.tenant');
Route::get('incidents', [IncidentController::class, 'index'])->middleware('api.tenant');
Route::post('incidents', [IncidentController::class, 'store'])->middleware('api.tenant');
Route::get('incidents/{incidentId}', [IncidentController::class, 'show'])->middleware('api.tenant');
Route::patch('incidents/{incidentId}/status', [IncidentController::class, 'updateStatus'])->middleware('api.tenant');
Route::get('corrective-actions', [CorrectiveActionController::class, 'index'])->middleware('api.tenant');
Route::post('corrective-actions', [CorrectiveActionController::class, 'store'])->middleware('api.tenant');
Route::get('corrective-actions/{actionId}', [CorrectiveActionController::class, 'show'])->middleware('api.tenant');
Route::patch('corrective-actions/{actionId}', [CorrectiveActionController::class, 'update'])->middleware('api.tenant');
Route::get('corrective-actions/{actionId}/updates', [CorrectiveActionUpdateController::class, 'index'])->middleware('api.tenant');
Route::post('corrective-actions/{actionId}/updates', [CorrectiveActionUpdateController::class, 'store'])->middleware('api.tenant');
Route::get('evidences', [EvidenceController::class, 'index'])->middleware('api.tenant');
Route::post('evidences', [EvidenceController::class, 'store'])->middleware('api.tenant');
Route::get('evidences/{evidenceId}', [EvidenceController::class, 'show'])->middleware('api.tenant');
Route::get('evidences/{evidenceId}/download', [EvidenceController::class, 'download'])->middleware('api.tenant');
Route::get('evidences/{evidenceId}/preview', [EvidenceController::class, 'preview'])->middleware('api.tenant');
