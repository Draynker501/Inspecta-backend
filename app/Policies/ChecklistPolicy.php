<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\NormRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ChecklistPolicy extends EhsResourcePolicy
{
    protected function readPermission(): Permission
    {
        return Permission::ChecklistsView;
    }

    protected function writePermission(): Permission
    {
        return Permission::ChecklistsManage;
    }

    public function linkNormAny(User $user): bool
    {
        return $this->create($user)
            && $this->hasPermission($user, Permission::NormsView);
    }

    public function linkNorm(User $user, Model $model, NormRequirement $requirement): bool
    {
        return $this->linkNormAny($user)
            && $this->ownsTenant($user, $model)
            && $this->ownsTenant($user, $requirement);
    }

    public function unlinkNorm(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }
}
