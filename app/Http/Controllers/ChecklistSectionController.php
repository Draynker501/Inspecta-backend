<?php

namespace App\Http\Controllers;

use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChecklistSectionController extends Controller
{
    /**
     * GET /api/versions/{versionId}/sections
     * Lista las secciones de una versión.
     */
    public function index(Request $request,string $versionId): JsonResponse 
    {
        $version = ChecklistVersion::findOrFail($versionId);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sections = $version->sections()
            ->orderBy('display_order')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'data' => $sections->items(),
            'meta' => [
                'current_page' => $sections->currentPage(),
                'last_page' => $sections->lastPage(),
                'per_page' => $sections->perPage(),
                'total' => $sections->total(),
            ],
        ]);
    }

    /**
     * POST /api/versions/{versionId}/sections
     * Crea una sección dentro de una versión en borrador.
     */
    public function store(Request $request,string $versionId,TenantContext $tenantContext): JsonResponse 
    {
        $version = ChecklistVersion::findOrFail($versionId);

        if ($version->status !== 'draft') {
            throw ValidationException::withMessages([
                'version' => 'Solo se pueden agregar secciones a versiones en borrador.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $section = ChecklistSection::create([
            'id' => (string) Str::uuid(),
            'checklist_version_id' => $version->id,
            'organization_id' => $tenantContext->requireId(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'display_order' => $validated['display_order'] ?? 0,
        ]);

        return response()->json([
            'data' => $section,
        ], 201);
    }

    /**
     * GET /api/sections/{id}
     * Consulta una sección específica.
     */
    public function show(string $id): JsonResponse
    {
        $section = ChecklistSection::findOrFail($id);

        return response()->json([
            'data' => $section,
        ]);
    }

    /**
     * PUT /api/sections/{id}
     * Actualiza una sección de una versión en borrador.
     */
    public function update(Request $request,string $id): JsonResponse 
    {
        $section = ChecklistSection::findOrFail($id);

        if ($section->checklistVersion->status !== 'draft') {
            throw ValidationException::withMessages([
                'version' => 'Solo se pueden modificar secciones de versiones en borrador.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $section->update($validated);

        return response()->json([
            'data' => $section->refresh(),
        ]);
    }
}
