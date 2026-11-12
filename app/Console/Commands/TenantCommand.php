<?php

namespace App\Console\Commands;

use App\Authorization\Permission;
use App\Exceptions\InvalidTenantOrganizationException;
use App\Models\User;
use App\Support\Tenancy\TenantExecution;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

abstract class TenantCommand extends Command
{
    /** Identificador suministrado por código confiable, no credencial. */
    public ?string $tenantActorId = null;

    public function __construct()
    {
        parent::__construct();
        if (! $this->getDefinition()->hasOption('organization')) {
            $this->addOption('organization', null, InputOption::VALUE_REQUIRED, 'UUID de organización cliente activa');
        }
    }

    final public function handle(): int
    {
        try {
            return app(TenantExecution::class)->run(
                $this->option('organization'),
                $this->tenantActorId,
                $this->tenantPermission(),
                fn (?User $actor): int => $this->handleTenant($actor),
            );
        } catch (InvalidTenantOrganizationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    abstract protected function tenantPermission(): Permission;

    abstract protected function handleTenant(?User $actor): int;
}
