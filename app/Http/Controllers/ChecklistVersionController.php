<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\ChecklistVersion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChecklistVersionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, string $id): JsonResponse
    {
        $checklist = Checklist::findOrFail($id);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $versions = $checklist->checklistVersions()
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'data' => $versions->items(),
            'meta' => [
                'current_page' => $versions->currentPage(),
                'last_page' => $versions->lastPage(),
                'per_page' => $versions->perPage(),
                'total' => $versions->total(),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $id, TenantContext $tenantContext): JsonResponse
    {
        $checklist = Checklist::findOrFail($id);

        $validated = $request->validate([
            'version' => [
                'required',
                'string',
                'max:20',
                Rule::unique('checklist_versions', 'version')
                    ->where('checklist_id', $checklist->id),
            ],
        ]);

        $version = ChecklistVersion::create([
            'id' => (string) Str::uuid(),
            'checklist_id' => $checklist->id,
            'organization_id' => $tenantContext->requireId(),
            'version' => $validated['version'],
            'status' => 'draft',
            'created_by' => $request->user()->id,
            'published_at' => null,
        ]);

        return response()->json([
            'data' => $version,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id, string $versionId): JsonResponse
    {
        $checklist = Checklist::findOrFail($id);

        $version = $checklist->checklistVersions()
            ->findOrFail($versionId);

        return response()->json([
            'data' => $version,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update( Request $request,string $id,string $versionId): JsonResponse 
    {
        $checklist = Checklist::findOrFail($id);

        $version = $checklist->checklistVersions()
            ->findOrFail($versionId);

        if ($version->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden editar versiones en borrador.',
            ]);
        }

        $validated = $request->validate([
            'version' => [
                'required',
                'string',
                'max:20',
                Rule::unique('checklist_versions', 'version')
                    ->where('checklist_id', $checklist->id)
                    ->ignore($version->id),
            ],
        ]);

        $version->update([
            'version' => $validated['version'],
        ]);

        return response()->json([
            'data' => $version->refresh(),
        ]);
    }

    public function updateStatus(Request $request,string $id,string $versionId): JsonResponse 
    {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in(['published', 'archived']),
            ],
        ]);

        $version = DB::transaction(function () use (
            $id,
            $versionId,
            $validated
        ) {
            // Bloqueamos el checklist para serializarlas publicaciones de sus versiones.
            $checklist = Checklist::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $version = $checklist->checklistVersions()
                ->findOrFail($versionId);

            $newStatus = $validated['status'];

            if ($version->status === 'archived') {
                throw ValidationException::withMessages([
                    'status' => 'Una versión archivada no puede cambiar de estado.',
                ]);
            }

            if ($version->status === $newStatus) {
                return $version;
            }

            if ($newStatus === 'published') {
                if ($version->status !== 'draft') {
                    throw ValidationException::withMessages([
                        'status' => 'Solo se pueden publicar versiones en borrador.',
                    ]);
                }

                // Archivar la versión publicada anteriormente.
                $checklist->checklistVersions()
                    ->where('status', 'published')
                    ->where('id', '!=', $version->id)
                    ->update([
                        'status' => 'archived',
                    ]);

                $version->update([
                    'status' => 'published',
                    'published_at' => now(),
                ]);
            } else {
                $version->update([
                    'status' => 'archived',
                ]);
            }

            return $version->refresh();
        });

        return response()->json([
            'data' => $version,
        ]);
    }
}
