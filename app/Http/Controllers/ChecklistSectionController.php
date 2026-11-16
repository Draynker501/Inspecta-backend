<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\ChecklistSection;
use App\Models\ChecklistVersion;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChecklistSectionController extends TenantController
{
    /**
     * Display a listing of the resource.
     */
    // Adaptado del listado de origin/api 6532e23; se verifica toda la jerarquía.
    public function index(Request $request, string $versionId): JsonResponse
    {
        Gate::authorize('viewAny', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $version = ChecklistVersion::where('organization_id', $organizationId)
            ->whereHas('checklist', fn ($query) => $query->where('organization_id', $organizationId))
            ->findOrFail($versionId);
        Gate::authorize('view', $version->checklist);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $sections = $version->sections()->where('organization_id', $organizationId)
            ->orderBy('display_order')->orderBy('id')
            ->paginate($validated['per_page'] ?? 15, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json(['data' => $sections->items(), 'meta' => [
            'current_page' => $sections->currentPage(), 'last_page' => $sections->lastPage(),
            'per_page' => $sections->perPage(), 'total' => $sections->total(),
        ]]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    // Adaptado de origin/api 6532e23, 8d0ab11 y 585b6d4.
    public function store(Request $request, string $versionId): JsonResponse
    {
        Gate::authorize('create', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();

        $section = DB::transaction(function () use ($request, $versionId, $organizationId): ChecklistSection {
            Organization::query()->lockForUpdate()->findOrFail($organizationId);
            $candidate = ChecklistVersion::where('organization_id', $organizationId)
                ->whereHas('checklist', fn ($query) => $query->where('organization_id', $organizationId))
                ->findOrFail($versionId);
            // Orden común: organización, checklist y versión.
            $checklist = Checklist::where('organization_id', $organizationId)
                ->lockForUpdate()->findOrFail($candidate->checklist_id);
            Gate::authorize('update', $checklist);
            $version = ChecklistVersion::where('organization_id', $organizationId)
                ->where('checklist_id', $checklist->id)->lockForUpdate()->findOrFail($versionId);

            if ($version->status !== 'draft' || $version->published_at !== null) {
                throw ValidationException::withMessages(['version' => 'Solo se pueden modificar versiones en borrador nunca publicadas.']);
            }
            if (array_diff(array_keys($request->all()), ['name', 'description', 'display_order']) !== []) {
                throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
            }
            $validated = $request->validate($this->sectionRules(true));

            $max = (int) DB::table('checklist_sections')->where('checklist_version_id', $version->id)->max('display_order');
            $order = $validated['display_order'] ?? $max + 1;
            if ($order > 2147483647 || DB::table('checklist_sections')->where('checklist_version_id', $version->id)->where('display_order', $order)->exists()) {
                throw ValidationException::withMessages(['display_order' => 'El orden no está disponible.']);
            }
            try {
                $section = ChecklistSection::create([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organizationId,
                    'checklist_version_id' => $version->id,
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'display_order' => $order,
                ]);
            } catch (QueryException $exception) {
                $info = $exception->errorInfo;
                $driver = DB::connection($exception->getConnectionName())->getDriverName();
                $sqlite = $driver === 'sqlite' && ($info[0] ?? null) === '23000' && (int) ($info[1] ?? 0) === 19
                    && ($info[2] ?? null) === 'UNIQUE constraint failed: checklist_sections.checklist_version_id, checklist_sections.display_order';
                $maria = in_array($driver, ['mariadb', 'mysql'], true) && ($info[0] ?? null) === '23000'
                    && (int) ($info[1] ?? 0) === 1062 && is_string($info[2] ?? null)
                    && str_ends_with($info[2], " for key 'sections_version_order_unique'");
                if ($sqlite || $maria) {
                    throw ValidationException::withMessages(['display_order' => 'El orden no está disponible.']);
                }
                throw $exception;
            }
            app(AuditLogger::class)->record('checklist_section.created', $section, [], [
                'checklist_version_id' => $version->id,
                'name' => $section->name,
                'display_order' => $section->display_order,
            ]);

            return $section;
        });

        return response()->json(['data' => $section], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $sectionId): JsonResponse
    {
        Gate::authorize('viewAny', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $section = ChecklistSection::where('organization_id', $organizationId)
            ->whereHas('checklistVersion', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->whereHas('checklist', fn ($checklists) => $checklists->where('organization_id', $organizationId)))
            ->findOrFail($sectionId);
        Gate::authorize('view', $section->checklistVersion->checklist);

        $section->unsetRelation('checklistVersion');

        return response()->json(['data' => $section]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ChecklistSection $checklistSection)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    // Adaptado de origin/api 585b6d4; autorización, locks y auditoría reforzados.
    public function update(Request $request, string $sectionId): JsonResponse
    {
        Gate::authorize('create', Checklist::class);
        $organizationId = app(TenantContext::class)->requireId();
        $section = DB::transaction(function () use ($request, $sectionId, $organizationId): ChecklistSection {
            Organization::query()->lockForUpdate()->findOrFail($organizationId);
            $candidate = ChecklistSection::where('organization_id', $organizationId)->findOrFail($sectionId);
            $candidateVersion = ChecklistVersion::where('organization_id', $organizationId)->findOrFail($candidate->checklist_version_id);
            $checklist = Checklist::where('organization_id', $organizationId)->lockForUpdate()->findOrFail($candidateVersion->checklist_id);
            Gate::authorize('update', $checklist);
            $version = ChecklistVersion::where('organization_id', $organizationId)->where('checklist_id', $checklist->id)
                ->lockForUpdate()->findOrFail($candidateVersion->id);
            $section = ChecklistSection::where('organization_id', $organizationId)->where('checklist_version_id', $version->id)
                ->lockForUpdate()->findOrFail($sectionId);
            if ($version->status !== 'draft' || $version->published_at !== null) {
                throw ValidationException::withMessages(['version' => 'Solo se pueden modificar versiones en borrador nunca publicadas.']);
            }
            if (array_diff(array_keys($request->all()), ['name', 'description', 'display_order']) !== []) {
                throw ValidationException::withMessages(['fields' => 'La solicitud contiene campos no permitidos.']);
            }
            $validated = $request->validate($this->sectionRules(false));
            if (isset($validated['display_order']) && DB::table('checklist_sections')->where('checklist_version_id', $version->id)
                ->where('id', '!=', $section->id)->where('display_order', $validated['display_order'])->exists()) {
                throw ValidationException::withMessages(['display_order' => 'El orden no está disponible.']);
            }
            $old = $section->only(array_keys($validated));
            $section->fill($validated);
            if ($section->isDirty()) {
                $section->save();
                app(AuditLogger::class)->record('checklist_section.updated', $section, $old, $section->only(array_keys($validated)));
            }

            return $section;
        });

        return response()->json(['data' => $section]);
    }

    private function sectionRules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('La descripción supera el máximo de 65535 bytes.');
                }
            }],
            'display_order' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ChecklistSection $checklistSection)
    {
        //
    }
}
