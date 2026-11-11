<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChecklistController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $validated['per_page'] ?? 15;

        $checklists = Checklist::query()
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $checklists->items(),
            'meta' => [
                'current_page' => $checklists->currentPage(),
                'last_page' => $checklists->lastPage(),
                'per_page' => $checklists->perPage(),
                'total' => $checklists->total(),
            ],
        ]);
    }


    /**
     * GET /api/checklists/{checklistId}/active-version
     * Obtiene la versión publicada y su estructura completa.
     */
    public function activeVersion(string $checklistId): JsonResponse
    {
        $checklist = Checklist::findOrFail($checklistId);

        $activeVersion = $checklist->checklistVersions()
            ->where('status', 'published')
            ->with([
                'sections' => function ($query) {
                    $query->orderBy('display_order')
                        ->orderBy('id');
                },
                'sections.questions' => function ($query) {
                    $query->where('status', true)
                        ->orderBy('display_order')
                        ->orderBy('id');
                },
                'sections.questions.normRequirements' => function ($query) {
                    $query->orderBy('norm_requirements.code')
                        ->orderBy('norm_requirements.id');
                },
            ])
            ->firstOrFail();

        $sections = $activeVersion->sections->map(
            function ($section) {
                $sectionData = $section->toArray();

                $sectionData['questions'] = $section->questions
                    ->map(function ($question) {
                        $questionData = $question->toArray();

                        $questionData['norm_requirements'] =
                            $question->normRequirements
                                ->map(function ($requirement) {
                                    $requirementData = $requirement->toArray();

                                    unset($requirementData['pivot']);

                                    return [
                                        'link_id' => $requirement->pivot->id,
                                        ...$requirementData,
                                    ];
                                })
                                ->values()
                                ->all();

                        return $questionData;
                    })
                    ->values()
                    ->all();

                return $sectionData;
            }
        );

        $checklistData = $checklist->toArray();

        $checklistData['active_version'] = [
            'id' => $activeVersion->id,
            'version' => $activeVersion->version,
            'status' => $activeVersion->status,
            'published_at' => $activeVersion->published_at,
            'sections' => $sections->values()->all(),
        ];

        return response()->json([
            'data' => $checklistData,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $organizationId = $tenantContext->requireId();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'organization_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::in([$organizationId]),
            ],
        ]);

        $checklist = Checklist::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => true,
        ]);

        return response()->json([
            'data' => $checklist,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $checklist = Checklist::findOrFail($id);

        return response()->json([
            'data' => $checklist,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $checklist = Checklist::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $checklist->update($validated);

        return response()->json([
            'data' => $checklist->refresh(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $checklist = Checklist::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'boolean'],
        ]);

        $checklist->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'data' => $checklist->refresh(),
        ]);
    }
}
