<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

class IncidentPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->activeClient()
            && $this->hasTenantContext($user)
            && (
                $user->hasPermission(Permission::FindingsRegister)
                || $user->hasPermission(Permission::FindingsClose)
                || $user->hasPermission(Permission::CorrectiveActionsManage)
            );
    }

    public function create(User $user): bool
    {
        return $this->activeClient()
            && $this->hasPermission($user, Permission::FindingsRegister);
    }

    public function view(User $user, Model $model): bool
    {
        if (! $model instanceof Incident) {
            return false;
        }

        return $this->activeClient()
            && $this->ownsTenant($user, $model)
            && $this->viewAny($user)
            && app(InspectionPolicy::class)->hasLocationAccess(
                $user,
                $model->plant_id,
                $model->area_id
            );
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof Incident
            && $this->view($user, $model)
            && $this->hasPermission(
                $user,
                Permission::FindingsClose
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
