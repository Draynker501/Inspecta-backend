<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

class CorrectiveActionPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->activeClient()
            && $this->hasTenantContext($user)
            && (
                $user->hasPermission(Permission::CorrectiveActionsManage)
                || $user->hasPermission(Permission::FindingsRegister)
                || $user->hasPermission(Permission::FindingsClose)
            );
    }

    public function create(User $user): bool
    {
        return $this->activeClient()
            && $this->hasPermission(
                $user,
                Permission::CorrectiveActionsManage
            );
    }

    public function view(User $user, Model $model): bool
    {
        if (! $model instanceof CorrectiveAction) {
            return false;
        }

        if (! $this->ownsTenant($user, $model)
            || ! $this->viewAny($user)) {
            return false;
        }

        $incident = Incident::query()
            ->where('organization_id', app(TenantContext::class)->id())
            ->find($model->incident_id);

        return $incident !== null
            && app(InspectionPolicy::class)->hasLocationAccess(
                $user,
                $incident->plant_id,
                $incident->area_id
            );
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof CorrectiveAction
            && $this->view($user, $model)
            && $this->hasPermission(
                $user,
                Permission::CorrectiveActionsManage
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
