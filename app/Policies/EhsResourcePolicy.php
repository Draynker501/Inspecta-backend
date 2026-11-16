<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class EhsResourcePolicy extends TenantPolicy
{
    abstract protected function readPermission(): Permission;

    abstract protected function writePermission(): Permission;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, $this->readPermission());
    }

    public function view(User $user, Model $model): bool
    {
        return $this->ownsTenant($user, $model)
            && $this->hasPermission($user, $this->readPermission());
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, $this->writePermission());
    }

    public function update(User $user, Model $model): bool
    {
        return $this->ownsTenant($user, $model)
            && $this->hasPermission($user, $this->writePermission());
    }
}
