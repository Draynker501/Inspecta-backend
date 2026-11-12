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
use App\Models\Question;

class ChecklistVersionController extends TenantController
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
    public function update(Request $request, string $id, string $versionId): JsonResponse
    {
        $validated = $request->validate([
            'version' => [
                'required',
                'string',
                'max:20',
                Rule::unique('checklist_versions', 'version')
                    ->where('checklist_id', $id)
                    ->ignore($versionId),
            ],
        ]);

        $version = DB::transaction(function () use ($id, $versionId, $validated) {
            $checklist = Checklist::findOrFail($id);

            // Bloquear la versión para evitar modificaciones simultáneas con su publicación.
            $version = $checklist->checklistVersions()
                ->lockForUpdate()
                ->findOrFail($versionId);

            // Solo se permiten cambios en versiones en borrador.
            if ($version->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Solo se pueden editar versiones en borrador.',
                ]);
            }

            $version->update([
                'version' => $validated['version'],
            ]);

            return $version->refresh();
        });

        return response()->json([
            'data' => $version,
        ]);
    }

    public function updateStatus(Request $request, string $id, string $versionId): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in(['published', 'archived']),
            ],
        ]);

        $version = DB::transaction(function () use ($id, $versionId, $validated) {
            // checklist bloqueado para serializar las publicaciones de sus versiones.
            $checklist = Checklist::query()
                ->lockForUpdate()
                ->findOrFail($id);

            $version = $checklist->checklistVersions()
                ->lockForUpdate()
                ->findOrFail($versionId);

            $newStatus = $validated['status'];

            // Una versión archivada no puede reactivarse.
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

                // impedir publicar checklists desactivados.
                if (!$checklist->status) {
                    throw ValidationException::withMessages([
                        'status' => 'No se puede publicar una versión de un checklist desactivado.',
                    ]);
                }

                // Verificar que el checklist tenga contenido válido.
                $this->validateBeforePublishing($version);

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

    private function validateBeforePublishing(ChecklistVersion $version): void
    {
        if (!$version->sections()->exists()) {
            throw ValidationException::withMessages([
                'sections' => 'La versión necesita al menos una sección antes de publicarse.',
            ]);
        }

        $emptySections = $version->sections()
            ->whereDoesntHave('questions', function ($query) {
                $query->where('status', true);
            })
            ->pluck('name');

        if ($emptySections->isNotEmpty()) {
            throw ValidationException::withMessages([
                'sections' => 'No se puede publicar el checklist. '
                    . 'Las siguientes secciones no tienen preguntas activas: '
                    . $emptySections->implode(', '),
            ]);
        }

        $questions = Question::query()
            ->whereIn(
                'checklist_section_id',
                $version->sections()->select('id')
            )
            ->where('status', true)
            ->get();

        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'questions' => 'La versión necesita al menos una pregunta activa.',
            ]);
        }

        foreach ($questions as $question) {
            if ($question->answer_type !== 'multiple_choice') {
                continue;
            }

            $options = $question->options ?? [];

            if (count($options) < 2) {
                throw ValidationException::withMessages([
                    'questions' => 'La pregunta ' . $question->id . ' necesita al menos dos opciones.',
                ]);
            }

            $values = array_column($options, 'value');

            if (
                count($values) !== count($options) ||
                count(array_unique($values)) !== count($values) ||
                in_array('', $values, true)
            ) {
                throw ValidationException::withMessages([
                    'questions' => 'La pregunta ' . $question->id . ' tiene opciones inválidas.',
                ]);
            }
        }
    }

}
