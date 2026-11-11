<?php

namespace App\Http\Controllers;

use App\Models\Norm;
use App\Models\NormRequirement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NormRequirementController extends Controller
{
    /**
     * GET /api/norms/{normId}/requirements
     * Lista los requisitos de una norma.
     */
    public function index(Request $request, string $normId): JsonResponse
    {
        $norm = Norm::findOrFail($normId);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $requirements = $norm->normRequirements()
            ->orderBy('code')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'data' => $requirements->items(),
            'meta' => [
                'current_page' => $requirements->currentPage(),
                'last_page' => $requirements->lastPage(),
                'per_page' => $requirements->perPage(),
                'total' => $requirements->total(),
            ],
        ]);
    }

    /**
     * POST /api/norms/{normId}/requirements
     * Crea un requisito dentro de una norma.
     */
    public function store(Request $request, string $normId, TenantContext $tenantContext): JsonResponse
    {
        $norm = Norm::findOrFail($normId);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('norm_requirements', 'code')->where('norm_id', $norm->id),
            ],
            'section' => ['sometimes', 'nullable', 'string', 'max:100',],
            'description' => ['required', 'string', 'max:65535',],
        ]);

        $requirement = NormRequirement::create([
            'id' => (string) Str::uuid(),
            'norm_id' => $norm->id,
            'organization_id' => $tenantContext->requireId(),
            'code' => $validated['code'],
            'section' => $validated['section'] ?? null,
            'description' => $validated['description'],
        ]);

        return response()->json([
            'data' => $requirement,
        ], 201);
    }

    /**
     * GET /api/requirements/{id}
     * Consulta un requisito específico.
     */
    public function show(string $id): JsonResponse
    {
        $requirement = NormRequirement::findOrFail($id);

        return response()->json([
            'data' => $requirement,
        ]);
    }

    /**
     * PUT /api/requirements/{id}
     * Actualiza un requisito existente.
     */
    public function update(Request $request,string $id): JsonResponse 
    {
        $requirement = NormRequirement::findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('norm_requirements', 'code')
                    ->where('norm_id', $requirement->norm_id)
                    ->ignore($requirement->id),
            ],
            'section' => ['sometimes','nullable','string','max:100',],
            'description' => ['sometimes','required','string','max:65535',],
        ]);

        $requirement->update($validated);

        return response()->json([
            'data' => $requirement->refresh(),
        ]);
    }
}
