<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\Evidence;
use App\Models\Inspection;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

class EvidencePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->activeClient()
            && $this->hasTenantContext($user)
            && (
                $user->hasPermission(Permission::InspectionsExecute)
                || $user->hasPermission(Permission::InspectionsManage)
            );
    }

    public function create(User $user): bool
    {
        return $this->activeClient()
            && $this->hasPermission(
                $user,
                Permission::InspectionsExecute
            );
    }

    public function view(User $user, Model $model): bool
    {
        if (! $model instanceof Evidence
            || ! $this->ownsTenant($user, $model)
            || ! $this->viewAny($user)) {
            return false;
        }

        $inspection = Inspection::query()
            ->where('organization_id', app(TenantContext::class)->id())
            ->find($model->inspection_id);

        return $inspection !== null
            && app(InspectionPolicy::class)->view(
                $user,
                $inspection
            );
    }

    public function uploadTo(
        User $user,
        Inspection $inspection
    ): bool {
        return $this->create($user)
            && $inspection->status === 'draft'
            && app(InspectionPolicy::class)->answer(
                $user,
                $inspection
            );
    }

    private function activeClient(): bool
    {
        $organizationId = app(TenantContext::class)->id();

        return $organizationId !== null
            && Organization::query()
                ->whereKey($organizationId)
                ->where('type', Organization::TYPE_CLIENT)
                ->where('status', true)
                ->exists();
    }
}
