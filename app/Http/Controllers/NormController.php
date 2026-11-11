<?php

namespace App\Http\Controllers;

use App\Models\Norm;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NormController extends Controller
{
    /**
     * GET /api/norms
     * Lista las normas de la organización.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $norms = Norm::query()
            ->orderBy('code')
            ->orderBy('version')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'data' => $norms->items(),
            'meta' => [
                'current_page' => $norms->currentPage(),
                'last_page' => $norms->lastPage(),
                'per_page' => $norms->perPage(),
                'total' => $norms->total(),
            ],
        ]);
    }

    /**
     * POST /api/norms
     * Registra una nueva norma.
     */
    public function store(Request $request,TenantContext $tenantContext): JsonResponse 
    {
        $validated = $request->validate([
            'code' => ['required','string','max:100',],
            'name' => ['required','string','max:150',],
            'description' => ['sometimes','nullable','string',],
            'issuing_body' => ['sometimes','nullable','string','max:150',],
            'version' => ['sometimes','nullable','string','max:50',],
            'effective_date' => ['sometimes','nullable','date_format:Y-m-d',],
        ]);

        $this->validateUniqueNorm(
            $validated['code'],
            $validated['version'] ?? null
        );

        $norm = Norm::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenantContext->requireId(),
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'issuing_body' => $validated['issuing_body'] ?? null,
            'version' => $validated['version'] ?? null,
            'effective_date' => $validated['effective_date'] ?? null,
            'status' => true,
        ]);

        return response()->json([
            'data' => $norm,
        ], 201);
    }

    /**
     * GET /api/norms/{id}
     * Consulta una norma específica.
     */
    public function show(string $id): JsonResponse
    {
        $norm = Norm::findOrFail($id);

        return response()->json([
            'data' => $norm,
        ]);
    }

    /**
     * PUT /api/norms/{id}
     * Actualiza una norma existente.
     */
    public function update( Request $request,string $id): JsonResponse 
    {
        $norm = Norm::findOrFail($id);

        $validated = $request->validate([
            'code' => ['required','string','max:100',],
            'name' => ['required','string','max:150',],
            'description' => ['sometimes','nullable','string',],
            'issuing_body' => ['sometimes','nullable','string','max:150',],
            'version' => ['sometimes','nullable','string','max:50',],
            'effective_date' => ['sometimes','nullable','date_format:Y-m-d',],
        ]);

        $this->validateUniqueNorm(
            $validated['code'] ?? $norm->code,
            array_key_exists('version', $validated)
                ? $validated['version']
                : $norm->version,
            $norm->id
        );

        $norm->update($validated);

        return response()->json([
            'data' => $norm->refresh(),
        ]);
    }

    /**
     * PATCH /api/norms/{id}/status
     * Alterna el estado de una norma.
     */
    public function updateStatus(string $id): JsonResponse
    {
        $norm = Norm::findOrFail($id);

        $norm->update([
            'status' => ! $norm->status,
        ]);

        return response()->json([
            'data' => $norm->refresh(),
        ]);
    }

    /**
     * Evita registrar el mismo código y versión
     * dentro de la organización activa.
     */
    private function validateUniqueNorm(string $code,?string $version,?string $ignoreId = null): void 
    {
        $query = Norm::query()
            ->where('code', $code);

        if ($version === null) {
            $query->whereNull('version');
        } else {
            $query->where('version', $version);
        }

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'code' => 'Ya existe una norma con el mismo código y versión.',
            ]);
        }
    }
}
