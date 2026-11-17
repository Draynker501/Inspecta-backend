<?php

namespace App\Http\Controllers;

use App\Models\Evidence;
use App\Models\Incident;
use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EvidenceController extends TenantController
{
    private const DISK = 'local';

    /**
     * GET /api/evidences
     * Lista las evidencias de la organización activa.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100',],
            'inspection_id' => ['sometimes', 'uuid'],
            'inspection_answer_id' => ['sometimes', 'uuid'],
            'incident_id' => ['sometimes', 'uuid'],
        ]);

        $query = Evidence::query();

        foreach ([
            'inspection_id',
            'inspection_answer_id',
            'incident_id',
        ] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        $evidences = $query
            ->orderByDesc('uploaded_at')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'data' => $evidences->items(),
            'meta' => [
                'current_page' => $evidences->currentPage(),
                'last_page' => $evidences->lastPage(),
                'per_page' => $evidences->perPage(),
                'total' => $evidences->total(),
            ],
        ]);
    }

    /**
     * POST /api/evidences
     * Almacena un archivo privado y registra sus metadatos.
     */
    public function store(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $organizationId = $tenantContext->requireId();

        $validated = $request->validate([
            'organization_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::in([$organizationId]),
            ],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240',],
            'inspection_id' => ['required', 'uuid'],
            'inspection_answer_id' => ['sometimes', 'nullable', 'uuid',],
            'incident_id' => ['sometimes', 'nullable', 'uuid',],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535',],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90',],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180',],
            'captured_at' => ['sometimes', 'nullable', 'date',],
        ]);

        $this->validateReferences($validated);

        $file = $request->file('file');
        $id = (string) Str::uuid();

        // El archivo queda separado por organización.
        $directory = "evidences/{$organizationId}";
        $fileName = "{$id}.{$file->extension()}";

        $storagePath = $file->storeAs(
            $directory,
            $fileName,
            self::DISK
        );

        if ($storagePath === false) {
            throw new \RuntimeException(
                'No fue posible almacenar la evidencia.'
            );
        }

        try {
            $evidence = Evidence::create([
                'id' => $id,
                'organization_id' => $organizationId,
                'inspection_id' => $validated['inspection_id'],
                'inspection_answer_id' =>
                    $validated['inspection_answer_id'] ?? null,
                'incident_id' =>
                    $validated['incident_id'] ?? null,
                'uploaded_by' => $request->user()->id,
                'file_name' => Str::limit(
                    $file->getClientOriginalName(),
                    255,
                    ''
                ),
                'storage_path' => $storagePath,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'description' =>
                    $validated['description'] ?? null,
                'latitude' =>
                    $validated['latitude'] ?? null,
                'longitude' =>
                    $validated['longitude'] ?? null,
                'captured_at' =>
                    $validated['captured_at'] ?? null,
                'uploaded_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Si falla el registro en BD, elimina el archivo.
            Storage::disk(self::DISK)->delete($storagePath);

            throw $exception;
        }

        return response()->json([
            'data' => $evidence,
        ], 201);
    }

    /**
     * GET /api/evidences/{evidenceId}
     * Devuelve los metadatos de una evidencia.
     */
    public function show(string $evidenceId): JsonResponse
    {
        $evidence = Evidence::findOrFail($evidenceId);

        return response()->json([
            'data' => $evidence,
        ]);
    }

    /**
     * GET /api/evidences/{evidenceId}/download
     * Descarga el archivo original de una evidencia.
     */
    public function download(string $evidenceId): BinaryFileResponse
    {
        $evidence = Evidence::findOrFail($evidenceId);

        $disk = Storage::disk(self::DISK);

        if (!$disk->exists($evidence->storage_path)) {
            abort(404, 'El archivo de la evidencia no existe.');
        }

        return response()->download(
            $disk->path($evidence->storage_path),
            $evidence->file_name,
            [
                'Content-Type' =>
                    $evidence->mime_type ?? 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * GET /api/evidences/{evidenceId}/preview
     * Visualiza imágenes y PDF sin descarga forzada.
     */
    public function preview(string $evidenceId): BinaryFileResponse
    {
        $evidence = Evidence::findOrFail($evidenceId);

        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
        ];

        if (
            !in_array(
                $evidence->mime_type,
                $allowedMimeTypes,
                true
            )
        ) {
            abort(
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                'Este tipo de archivo no admite vista previa.'
            );
        }

        $disk = Storage::disk(self::DISK);

        if (!$disk->exists($evidence->storage_path)) {
            abort(404, 'El archivo de la evidencia no existe.');
        }

        return response()->file(
            $disk->path($evidence->storage_path),
            [
                'Content-Type' => $evidence->mime_type,
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            ]
        );
    }

    /**
     * Verifica la existencia y coherencia de las relaciones.
     */
    private function validateReferences(array $data): void
    {
        $inspection = Inspection::query()
            ->find($data['inspection_id']);

        if ($inspection === null) {
            throw ValidationException::withMessages([
                'inspection_id' =>
                    'La inspección no existe en la organización activa.',
            ]);
        }

        if (!empty($data['inspection_answer_id'])) {
            $answer = InspectionAnswer::query()
                ->whereKey($data['inspection_answer_id'])
                ->where('inspection_id', $inspection->id)
                ->first();

            if ($answer === null) {
                throw ValidationException::withMessages([
                    'inspection_answer_id' =>
                        'La respuesta no pertenece a la inspección indicada.',
                ]);
            }
        }

        if (!empty($data['incident_id'])) {
            $incident = Incident::query()
                ->whereKey($data['incident_id'])
                ->where('inspection_id', $inspection->id)
                ->first();

            if ($incident === null) {
                throw ValidationException::withMessages([
                    'incident_id' =>
                        'El incidente no pertenece a la inspección indicada.',
                ]);
            }
        }
    }
}