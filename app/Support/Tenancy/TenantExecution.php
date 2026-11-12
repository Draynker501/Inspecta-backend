<?php

namespace App\Support\Tenancy;

use App\Authorization\Permission;
use App\Exceptions\InvalidTenantOrganizationException;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * El UUID identifica, pero no autentica: solo para productores confiables.
 * No admite procesos de sistema ni productores externos sin autoridad aprobada.
 */
final class TenantExecution
{
    public function __construct(private TenantContext $context) {}

    public function run(?string $organizationId, ?string $actorId, Permission $permission, Closure $operation): mixed
    {
        if (\Fiber::getCurrent() !== null) {
            throw new \LogicException('No se admite ejecución tenant en fibras concurrentes.');
        }

        return $this->context->isolated(fn () => $this->context->withAuditActor(null, function () use ($organizationId, $actorId, $permission, $operation) {
            if (! is_string($organizationId) || ! Str::isUuid($organizationId)) {
                throw new InvalidTenantOrganizationException('Se requiere un UUID de organización válido.');
            }
            $organization = Organization::query()->whereKey($organizationId)
                ->where('type', Organization::TYPE_CLIENT)->where('status', true)->first();
            if (! $organization) {
                throw new InvalidTenantOrganizationException('La organización debe ser un cliente activo existente.');
            }
            if ($actorId === null) {
                throw new AuthorizationException;
            }

            return $this->context->runAs($organizationId, function () use ($actorId, $organizationId, $permission, $operation) {
                $actor = null;
                if ($actorId !== null) {
                    $actor = Str::isUuid($actorId) ? User::query()->whereKey($actorId)->where('status', true)->first() : null;
                    if (! $actor || ! OrganizationMembership::findActive($actorId, $organizationId)
                        || ! $actor->hasPermission($permission)) {
                        throw new AuthorizationException;
                    }
                }

                return $this->context->withAuditActor($actor?->id, function () use ($actor, $operation) {
                    return $operation($actor);
                });
            });
        }));
    }
}
