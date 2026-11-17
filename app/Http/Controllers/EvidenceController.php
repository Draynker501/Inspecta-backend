<?php

namespace App\Http\Controllers;

use App\Models\Evidence;
use App\Models\Incident;
use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Models\Organization;
use App\Policies\EvidencePolicy;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EvidenceController extends TenantController
{
    private const DISK = 'local';

    private const MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Evidence::class);

        $this->rejectUnknownFields(
            $request->query(),
            [
                'page', 'per_page', 'inspection_id',
                'inspection_answer_id', 'incident_id',
            ]
        );

        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'inspection_id' => ['sometimes', 'uuid'],
            'inspection_answer_id' => ['sometimes', 'uuid'],
            'incident_id' => ['sometimes', 'uuid'],
        ]);

        $org = app(TenantContext::class)->requireId();
        $user = $request->user();

        $query = Evidence::query()
            ->where('organization_id', $org)
            ->whereHas('inspection', function ($query) use ($org, $user) {
                $query->where('organization_id', $org);

                if (! $user->hasPermission(
                    \App\Authorization\Permission::InspectionsManage
                )) {
                    $query->where('user_id', $user->id);
                }

                $query->where(function ($location) use ($org, $user) {
                    $location->whereIn('plant_id', function ($sub) use ($org, $user) {
                        $sub->select('plant_id')
                            ->from('user_plants')
                            ->where('organization_id', $org)
                            ->where('user_id', $user->id);
                    })->orWhereIn('area_id', function ($sub) use ($org, $user) {
                        $sub->select('area_id')
                            ->from('user_areas')
                            ->where('organization_id', $org)
                            ->where('user_id', $user->id);
                    });
                });
            });

        foreach ([
            'inspection_id',
            'inspection_answer_id',
            'incident_id',
        ] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        $page = $query
            ->orderByDesc('uploaded_at')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate(
                $data['per_page'] ?? 15,
                ['*'],
                'page',
                $data['page'] ?? 1
            );

        return response()->json([
            'data' => array_map(
                $this->dto(...),
                $page->items()
            ),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Evidence::class);

        if ($request->query() !== []) {
            throw ValidationException::withMessages([
                'fields' => 'No se admiten parámetros de consulta.',
            ]);
        }

        $this->rejectUnknownFields(
            array_merge(
                $request->all(),
                $request->allFiles()
            ),
            [
                'file', 'inspection_id', 'inspection_answer_id',
                'incident_id', 'description', 'latitude',
                'longitude', 'captured_at',
            ]
        );

        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:10240',
            ],
            'inspection_id' => ['required', 'uuid'],
            'inspection_answer_id' => [
                'sometimes', 'nullable', 'uuid',
            ],
            'incident_id' => ['sometimes', 'nullable', 'uuid'],
            'description' => [
                'sometimes', 'nullable', 'string', 'max:65535',
            ],
            'latitude' => [
                'sometimes', 'nullable', 'numeric', 'between:-90,90',
            ],
            'longitude' => [
                'sometimes', 'nullable', 'numeric', 'between:-180,180',
            ],
            'captured_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (
            isset($data['latitude']) !== isset($data['longitude'])
        ) {
            throw ValidationException::withMessages([
                'coordinates' =>
                    'Las coordenadas deben proporcionarse juntas.',
            ]);
        }

        $org = app(TenantContext::class)->requireId();
        $user = $request->user();
        $file = $request->file('file');

        $id = (string) Str::uuid7();
        $path = null;

        try {
            $evidence = DB::transaction(function () use (
                $org,
                $user,
                $data,
                $file,
                $id,
                &$path
            ): Evidence {
                Organization::query()
                    ->whereKey($org)
                    ->lockForUpdate()
                    ->firstOrFail();

                app(TenantContext::class)
                    ->flushPermissionCache($user->id, $org);

                Gate::authorize('create', Evidence::class);

                $inspection = Inspection::query()
                    ->where('organization_id', $org)
                    ->lockForUpdate()
                    ->findOrFail($data['inspection_id']);

                abort_unless(
                    app(EvidencePolicy::class)->uploadTo(
                        $user,
                        $inspection
                    ),
                    403
                );

                $this->validateReferences(
                    $data,
                    $inspection,
                    $org
                );

                $directory = "evidences/{$org}";
                $extension = $file->extension();

                $path = $file->storeAs(
                    $directory,
                    "{$id}.{$extension}",
                    self::DISK
                );

                if ($path === false) {
                    throw new \RuntimeException(
                        'No fue posible almacenar la evidencia.'
                    );
                }

                $evidence = Evidence::create([
                    'id' => $id,
                    'organization_id' => $org,
                    'inspection_id' => $inspection->id,
                    'inspection_answer_id' =>
                        $data['inspection_answer_id'] ?? null,
                    'incident_id' => $data['incident_id'] ?? null,
                    'uploaded_by' => $user->id,
                    'file_name' => Str::limit(
                        $file->getClientOriginalName(),
                        255,
                        ''
                    ),
                    'storage_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'description' => $data['description'] ?? null,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'captured_at' => $data['captured_at'] ?? null,
                    'uploaded_at' => now(),
                ]);

                app(AuditLogger::class)->record(
                    'evidence.created',
                    $evidence,
                    [],
                    [
                        'inspection_id' => $evidence->inspection_id,
                        'inspection_answer_id' =>
                            $evidence->inspection_answer_id,
                        'incident_id' => $evidence->incident_id,
                        'mime_type' => $evidence->mime_type,
                        'file_size' => $evidence->file_size,
                    ]
                );

                return $evidence;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $exception;
        }

        return response()->json([
            'data' => $this->dto($evidence),
        ], 201);
    }

    public function show(
        Request $request,
        string $evidenceId
    ): JsonResponse {
        return response()->json([
            'data' => $this->dto(
                $this->authorizedEvidence(
                    $request,
                    $evidenceId
                )
            ),
        ]);
    }

    public function download(
        Request $request,
        string $evidenceId
    ): BinaryFileResponse {
        $evidence = $this->authorizedEvidence(
            $request,
            $evidenceId
        );

        $disk = Storage::disk(self::DISK);

        abort_unless(
            $disk->exists($evidence->storage_path),
            404,
            'El archivo no existe.'
        );

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

    public function preview(
        Request $request,
        string $evidenceId
    ): BinaryFileResponse {
        $evidence = $this->authorizedEvidence(
            $request,
            $evidenceId
        );

        abort_unless(
            in_array(
                $evidence->mime_type,
                self::MIME_TYPES,
                true
            ),
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE
        );

        $disk = Storage::disk(self::DISK);

        abort_unless(
            $disk->exists($evidence->storage_path),
            404,
            'El archivo no existe.'
        );

        return response()->file(
            $disk->path($evidence->storage_path),
            [
                'Content-Type' => $evidence->mime_type,
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' =>
                    "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            ]
        );
    }

    private function authorizedEvidence(
        Request $request,
        string $evidenceId
    ): Evidence {
        Gate::authorize('viewAny', Evidence::class);

        $org = app(TenantContext::class)->requireId();

        $evidence = Evidence::query()
            ->where('organization_id', $org)
            ->findOrFail($evidenceId);

        abort_unless(
            app(EvidencePolicy::class)->view(
                $request->user(),
                $evidence
            ),
            404
        );

        return $evidence;
    }

    private function validateReferences(
        array $data,
        Inspection $inspection,
        string $org
    ): void {
        if (! empty($data['inspection_answer_id'])) {
            $answer = InspectionAnswer::query()
                ->where('organization_id', $org)
                ->where('inspection_id', $inspection->id)
                ->find($data['inspection_answer_id']);

            if ($answer === null) {
                throw ValidationException::withMessages([
                    'inspection_answer_id' =>
                        'La respuesta no pertenece a la inspección.',
                ]);
            }
        }

        if (! empty($data['incident_id'])) {
            $incident = Incident::query()
                ->where('organization_id', $org)
                ->where('inspection_id', $inspection->id)
                ->find($data['incident_id']);

            if ($incident === null) {
                throw ValidationException::withMessages([
                    'incident_id' =>
                        'El incidente no pertenece a la inspección.',
                ]);
            }
        }
    }

    private function rejectUnknownFields(
        array $data,
        array $allowed
    ): void {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw ValidationException::withMessages([
                'fields' =>
                    'La solicitud contiene campos no permitidos.',
            ]);
        }
    }

    private function dto(Evidence $evidence): array
    {
        return [
            'id' => $evidence->id,
            'organization_id' => $evidence->organization_id,
            'inspection_id' => $evidence->inspection_id,
            'inspection_answer_id' =>
                $evidence->inspection_answer_id,
            'incident_id' => $evidence->incident_id,
            'uploaded_by' => $evidence->uploaded_by,
            'file_name' => $evidence->file_name,
            'mime_type' => $evidence->mime_type,
            'file_size' => $evidence->file_size,
            'description' => $evidence->description,
            'latitude' => $evidence->latitude,
            'longitude' => $evidence->longitude,
            'captured_at' => $evidence->captured_at,
            'uploaded_at' => $evidence->uploaded_at,
            'created_at' => $evidence->created_at,
        ];
    }
}