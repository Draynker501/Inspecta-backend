<?php

namespace App\Jobs\Concerns;

use App\Authorization\Permission;
use App\Models\User;
use App\Support\Tenancy\TenantExecution;

/** El payload se construye explícitamente; no consulta contexto ambiente. */
trait TenantAwareJob
{
    public ?string $organizationId = null;

    public ?string $actorId = null;

    final public function handle(): mixed
    {
        return app(TenantExecution::class)->run(
            $this->organizationId,
            $this->actorId,
            $this->tenantPermission(),
            fn (?User $actor): mixed => $this->handleTenant($actor),
        );
    }

    abstract protected function tenantPermission(): Permission;

    abstract protected function handleTenant(?User $actor): mixed;
}
