<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Plant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlantController extends TenantController
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Plant::class);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $organizationId = app(TenantContext::class)->requireId();
        $perPage = $validated['per_page'] ?? 15;

        $query = Plant::query()->where('organization_id', $organizationId);

        if (! $user->hasPermission(Permission::LocationsViewAll)) {
            $query->whereIn('id', function ($subquery) use ($user, $organizationId): void {
                $subquery->select('plant_id')
                    ->from('user_plants')
                    ->where('user_id', $user->id)
                    ->where('organization_id', $organizationId);
            });
        }

        $plants = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $plants->items(),
            'meta' => [
                'current_page' => $plants->currentPage(),
                'per_page' => $plants->perPage(),
                'total' => $plants->total(),
                'last_page' => $plants->lastPage(),
            ],
        ]);
    }
}
