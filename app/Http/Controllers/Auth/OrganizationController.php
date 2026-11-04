<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;

/**
 * Consulta de organizaciones del usuario autenticado.
 *
 * Lista las organizaciones cliente activas en las que el usuario
 * autenticado tiene una membresía activa. No requiere
 * X-Organization-Id ni TenantContext.
 */
class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $memberships = $user->memberships()
            ->active()
            ->whereHas('organization', function ($query) {
                $query->where('type', Organization::TYPE_CLIENT)
                    ->where('status', true)
                    ->whereNull('deleted_at');
            })
            ->with('organization:id,name,code,type,status')
            ->get()
            ->map(fn ($membership) => [
                'id' => $membership->organization->id,
                'name' => $membership->organization->name,
                'code' => $membership->organization->code,
                'type' => $membership->organization->type,
                'status' => $membership->organization->status,
                'membership_id' => $membership->id,
                'membership_status' => $membership->status,
            ])
            ->values();

        return response()->json([
            'message' => 'Organizaciones del usuario.',
            'data' => $memberships,
        ]);
    }
}
