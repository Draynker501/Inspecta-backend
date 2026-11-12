<?php

namespace App\Http\Controllers;

use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class ChecklistSectionController extends TenantController
{
    /**
     * GET /api/versions/{versionId}/sections
     * Lista las secciones de una versión.
     */
    public function index(Request $request, string $versionId): JsonResponse
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

    public function store(Request $request, string $versionId, TenantContext $tenantContext): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'display_order' => ['sometimes', 'integer', 'min:1'],
        ]);

        $section = DB::transaction(function () use ($versionId, $validated, $tenantContext) {
            // Bloquear la versión mientras se crea la sección.
            $version = ChecklistVersion::query()
                ->lockForUpdate()
                ->findOrFail($versionId);

            // Verificar que todavía permita modificaciones.
            $this->ensureDraft($version);

            $displayOrder = $validated['display_order']
                ?? max(1, ((int) $version->sections()->max('display_order')) + 1);

            $orderExists = $version->sections()
                ->where('display_order', $displayOrder)
                ->exists();

            if ($orderExists) {
                throw ValidationException::withMessages([
                    'display_order' => 'Ya existe una sección con ese orden en la versión.',
                ]);
            }

            $section = ChecklistSection::create([
                'id' => (string) Str::uuid(),
                'checklist_version_id' => $version->id,
                'organization_id' => $tenantContext->requireId(),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'display_order' => $displayOrder,
            ]);

            return $section;
        });

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

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'display_order' => ['sometimes', 'integer', 'min:1'],
        ]);

        $section = DB::transaction(function () use ($id, $validated) {
            // Buscar la sección que se desea modificar.
            $section = ChecklistSection::findOrFail($id);

            // Bloquear la versión a la que pertenece.
            $version = ChecklistVersion::query()
                ->lockForUpdate()
                ->findOrFail($section->checklist_version_id);

            // Solo se permiten cambios en versiones draft.
            $this->ensureDraft($version);

            if (array_key_exists('display_order', $validated)) {
                $orderExists = $version->sections()
                    ->where('display_order', $validated['display_order'])
                    ->where('id', '!=', $section->id)
                    ->exists();

                if ($orderExists) {
                    throw ValidationException::withMessages([
                        'display_order' => 'Ya existe otra sección con ese orden en la versión.',
                    ]);
                }
            }

            // Actualizar la sección.
            $section->update($validated);

            return $section->refresh();
        });

        return response()->json([
            'data' => $section,
        ]);
    }

    private function ensureDraft(ChecklistVersion $version): void
    {
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages([
                'version' => 'Solo se pueden modificar secciones de versiones en borrador.',
            ]);
        }
    }
}
