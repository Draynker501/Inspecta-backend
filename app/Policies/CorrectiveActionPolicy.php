<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Models\CorrectiveAction;
use App\Models\CorrectiveActionUpdate;
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

    /**
     * Capacidad general de edición. La autorización efectiva sobre una
     * acción concreta la sigue resolviendo update() con su recurso.
     */
    public function updateAny(User $user): bool
    {
        return $this->create($user);
    }

    /**
     * Capacidad general de registrar avances. La autorización efectiva
     * sobre una acción concreta la sigue resolviendo recordUpdate().
     */
    public function recordUpdateAny(User $user): bool
    {
        return $this->create($user);
    }

    public function view(User $user, Model $model): bool
    {
        if (! $model instanceof CorrectiveAction) {
            return false;
        }

        if (
            ! $this->ownsTenant($user, $model)
            || ! $this->viewAny($user)
        ) {
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

    /**
     * Capacidad general de verificación. No autoriza una acción concreta;
     * verify() comprueba además organización, ubicación, estado y separación
     * entre ejecutor/responsable y verificador.
     */
    public function verifyAny(User $user): bool
    {
        return $this->activeClient()
            && $this->hasPermission(
                $user,
                Permission::CorrectiveActionsVerify
            )
            && $user->hasEligibleVerifierRole();
    }

    /**
     * Verificación independiente de una acción completada.
     *
     * El ejecutor se toma del avance append-only con status=completed, nunca
     * de assigned_to (que puede cambiar). Sin avance completed se deniega.
     */
    public function verify(User $user, Model $model): bool
    {
        if (! $model instanceof CorrectiveAction) {
            return false;
        }

        if (
            ! $this->verifyAny($user)
            || ! $this->view($user, $model)
            || $model->status !== 'completed'
        ) {
            return false;
        }

        $completion = CorrectiveActionUpdate::query()
            ->where('organization_id', app(TenantContext::class)->id())
            ->where('corrective_action_id', $model->id)
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $completion !== null
            && $completion->user_id !== $user->id
            && $model->assigned_to !== $user->id;
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

    public function recordUpdate(User $user, Model $model): bool
    {
        return $model instanceof CorrectiveAction
            && $this->update($user, $model);
    }
}
