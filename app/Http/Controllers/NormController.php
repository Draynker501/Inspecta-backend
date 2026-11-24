<?php

namespace App\Http\Controllers;

use App\Models\Norm;
use App\Support\Audit\AuditLogger;
use App\Support\Ehs\NormWriteGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NormController extends TenantController
{
    // Escrituras adaptadas de origin/api 5748bd9; mutex organizacional antes de cualquier lectura.
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Norm::class);
        $organizationId = app(TenantContext::class)->requireId();
        $norm = DB::transaction(function () use ($request, $organizationId) {
            app(NormWriteGuard::class)->lockOrganization($organizationId);
            $data = $this->validateContent($request);
            $this->assertUnique($organizationId, $data['code'], $data['version'] ?? null);
            $norm = Norm::create([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'issuing_body' => $data['issuing_body'] ?? null,
                'version' => $data['version'] ?? null,
                'effective_date' => $data['effective_date'] ?? null,
                'status' => true,
            ]);
            app(AuditLogger::class)->record('norm.created', $norm, [], $norm->only(['code', 'name', 'description', 'issuing_body', 'version', 'effective_date', 'status']));

            return $norm;
        });

        return response()->json(['data' => $norm], 201);
    }

    public function update(Request $request, string $normId): JsonResponse
    {
        Gate::authorize('create', Norm::class);
        $organizationId = app(TenantContext::class)->requireId();

        $norm = DB::transaction(function () use ($request, $normId, $organizationId) {
            $guard = app(NormWriteGuard::class);
            $guard->lockOrganization($organizationId);

            $norm = Norm::where('organization_id', $organizationId)
                ->lockForUpdate()
                ->findOrFail($normId);

            Gate::authorize('update', $norm);

            if (! $norm->status) {
                throw ValidationException::withMessages([
                    'status' => 'No se puede modificar una norma inactiva. Debe reactivarla primero.',
                ]);
            }

            $data = $this->validateContent($request);

            $guard->assertNotHistorical(
                $organizationId,
                normId: $norm->id
            );

            $this->assertUnique(
                $organizationId,
                $data['code'],
                array_key_exists('version', $data)
                ? $data['version']
                : $norm->version,
                $norm->id
            );

            $old = $norm->only(array_keys($data));

            $norm->update($data);

            app(AuditLogger::class)->record(
                'norm.updated',
                $norm,
                $old,
                $norm->only(array_keys($data))
            );

            return $norm->refresh();
        });

        return response()->json(['data' => $norm]);
    }

    public function updateStatus(Request $request, string $normId): JsonResponse
    {
        Gate::authorize('create', Norm::class);
        $organizationId = app(TenantContext::class)->requireId();
        $norm = DB::transaction(function () use ($request, $normId, $organizationId) {
            app(NormWriteGuard::class)->lockOrganization($organizationId);
            $norm = Norm::where('organization_id', $organizationId)->lockForUpdate()->findOrFail($normId);
            Gate::authorize('update', $norm);
            if (array_diff(array_keys($request->all()), ['status'])) {
                throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
            }
            $data = $request->validate(['status' => ['required', 'boolean']]);
            $old = $norm->status;
            if ($old !== (bool) $data['status']) {
                $norm->update(['status' => $data['status']]);
                app(AuditLogger::class)->record('norm.status_changed', $norm, ['status' => $old], ['status' => $norm->status]);
            }

            return $norm->refresh();
        });

        return response()->json(['data' => $norm]);
    }

    private function validateContent(Request $request): array
    {
        if (array_diff(array_keys($request->all()), ['code', 'name', 'description', 'issuing_body', 'version', 'effective_date'])) {
            throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
        }

        return $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:150'],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 65535) {
                        $fail('El texto supera el máximo de 65535 bytes.');
                    }
                },
            ],
            'issuing_body' => ['sometimes', 'nullable', 'string', 'max:150'],
            'version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'effective_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
    }

    private function assertUnique(string $organizationId, string $code, ?string $version, ?string $ignore = null): void
    {
        $query = Norm::where('organization_id', $organizationId)->where('code', $code)->where('version', $version);
        if ($ignore !== null) {
            $query->where('id', '!=', $ignore);
        }
        if ($query->lockForUpdate()->first() !== null) {
            throw ValidationException::withMessages(['code' => 'Ya existe una norma con el mismo código y versión.']);
        }
    }

    // Lecturas adaptadas de origin/api (5748bd9): autorización y tenant fail-closed.
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Norm::class);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $norms = Norm::query()
            ->where('organization_id', app(TenantContext::class)->requireId())
            ->orderBy('code')->orderBy('version')->orderBy('id')
            ->paginate($validated['per_page'] ?? 15, ['*'], 'page', $validated['page'] ?? 1);

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

    public function show(string $normId): JsonResponse
    {
        Gate::authorize('viewAny', Norm::class);

        $norm = Norm::query()->where('organization_id', app(TenantContext::class)->requireId())->findOrFail($normId);
        Gate::authorize('view', $norm);

        return response()->json(['data' => $norm]);
    }
}
