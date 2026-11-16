<?php

namespace App\Http\Controllers;

use App\Models\Norm;
use App\Models\NormRequirement;
use App\Support\Audit\AuditLogger;
use App\Support\Ehs\NormWriteGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NormRequirementController extends TenantController
{
    // Escrituras adaptadas de origin/api 9d71f54 con preservación histórica.
    public function store(Request $request, string $normId): JsonResponse
    {
        Gate::authorize('create', Norm::class);
        $organizationId = app(TenantContext::class)->requireId();
        $requirement = DB::transaction(function () use ($request, $normId, $organizationId) {
            app(NormWriteGuard::class)->lockOrganization($organizationId);
            $norm = Norm::where('organization_id', $organizationId)->lockForUpdate()->findOrFail($normId);
            Gate::authorize('update', $norm);
            $data = $this->validateContent($request, true);
            $this->assertUnique($norm->id, $data['code']);
            $requirement = NormRequirement::create([
                'id' => (string) Str::uuid7(), 'organization_id' => $organizationId, 'norm_id' => $norm->id,
                'code' => $data['code'], 'section' => $data['section'] ?? null, 'description' => $data['description'],
            ]);
            app(AuditLogger::class)->record('norm_requirement.created', $requirement, [], $requirement->only(['norm_id', 'code', 'section', 'description']));

            return $requirement;
        });

        return response()->json(['data' => $requirement], 201);
    }

    public function update(Request $request, string $requirementId): JsonResponse
    {
        Gate::authorize('create', Norm::class);
        $organizationId = app(TenantContext::class)->requireId();
        $requirement = DB::transaction(function () use ($request, $requirementId, $organizationId) {
            $guard = app(NormWriteGuard::class);
            $guard->lockOrganization($organizationId);
            $candidate = NormRequirement::where('organization_id', $organizationId)->findOrFail($requirementId);
            $norm = Norm::where('organization_id', $organizationId)->lockForUpdate()->findOrFail($candidate->norm_id);
            Gate::authorize('update', $norm);
            $requirement = NormRequirement::where('organization_id', $organizationId)->where('norm_id', $norm->id)
                ->lockForUpdate()->findOrFail($requirementId);
            $data = $this->validateContent($request, false);
            $guard->assertNotHistorical($organizationId, requirementId: $requirement->id);
            if (array_key_exists('code', $data)) {
                $this->assertUnique($norm->id, $data['code'], $requirement->id);
            }
            $old = $requirement->only(array_keys($data));
            $requirement->update($data);
            app(AuditLogger::class)->record('norm_requirement.updated', $requirement, $old, $requirement->only(array_keys($data)));

            return $requirement->refresh();
        });

        return response()->json(['data' => $requirement]);
    }

    private function validateContent(Request $request, bool $create): array
    {
        if (array_diff(array_keys($request->all()), ['code', 'section', 'description'])) {
            throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
        }

        return $request->validate([
            'code' => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:100'],
            'section' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => [$create ? 'required' : 'sometimes', 'required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('El texto supera el máximo de 65535 bytes.');
                }
            }],
        ]);
    }

    private function assertUnique(string $normId, string $code, ?string $ignore = null): void
    {
        $query = NormRequirement::where('norm_id', $normId)->where('code', $code);
        if ($ignore !== null) {
            $query->where('id', '!=', $ignore);
        }
        if ($query->lockForUpdate()->first() !== null) {
            throw ValidationException::withMessages(['code' => 'Ya existe un requisito con este código.']);
        }
    }

    // Lecturas adaptadas de origin/api (9d71f54); no se incorporan escrituras.
    public function index(Request $request, string $normId): JsonResponse
    {
        Gate::authorize('viewAny', Norm::class);
        $organizationId = app(TenantContext::class)->requireId();
        $norm = Norm::query()->where('organization_id', $organizationId)->findOrFail($normId);
        Gate::authorize('view', $norm);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $requirements = $norm->normRequirements()
            ->where('organization_id', $organizationId)
            ->orderBy('code')->orderBy('id')
            ->paginate($validated['per_page'] ?? 15, ['*'], 'page', $validated['page'] ?? 1);

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

    public function show(string $requirementId): JsonResponse
    {
        Gate::authorize('viewAny', Norm::class);
        $requirement = NormRequirement::query()
            ->where('organization_id', app(TenantContext::class)->requireId())
            ->whereHas('norm', fn ($query) => $query->where('organization_id', app(TenantContext::class)->requireId()))
            ->findOrFail($requirementId);
        Gate::authorize('view', $requirement->norm);

        return response()->json(['data' => $requirement]);
    }
}
