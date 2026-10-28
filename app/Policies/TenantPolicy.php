<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Base de autorización multi-tenant.
 *
 * Ninguna comprobación es válida sin contexto organizacional: si el
 * middleware no lo estableció, la política deniega (fail-closed). Las
 * políticas concretas heredan estas reglas y añaden alcance por planta,
 * área, propietario o rol.
 */
abstract class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantContext($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->ownsTenant($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->isTenantAdmin($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->ownsTenant($user, $model) && $this->isTenantAdmin($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    protected function hasTenantContext(User $user): bool
    {
        $organizationId = app(TenantContext::class)->id();

        return $organizationId !== null
            && $user->organization_id === $organizationId;
    }

    protected function ownsTenant(User $user, Model $model): bool
    {
        return $this->hasTenantContext($user)
            && $model->getAttribute('organization_id') === app(TenantContext::class)->id();
    }

    protected function isTenantAdmin(User $user): bool
    {
        return $this->hasTenantContext($user) && $user->isAdmin();
    }
}
