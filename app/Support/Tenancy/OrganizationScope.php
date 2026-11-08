<?php

namespace App\Support\Tenancy;

use App\Exceptions\MissingTenantContextException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra las consultas por la organización activa.
 *
 * En modo estricto (config tenancy.strict_mode), consultar un modelo
 * tenant-scoped sin contexto organizacional en una petición HTTP lanza
 * MissingTenantContextException. La consola (seeders, factories, artisan),
 * las colas NO (los jobs deben fijar tenant o usar withoutTenant) y
 * TenantContext::withoutTenant() quedan exentos.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $organizationId = $context->id();

        if ($organizationId !== null) {
            $builder->where($model->qualifyColumn('organization_id'), $organizationId);

            return;
        }

        if (! (bool) config('tenancy.strict_mode', false)) {
            return;
        }

        if ($context->isBypassingTenant()) {
            return;
        }

        if ($this->isConsoleExempt()) {
            return;
        }

        throw MissingTenantContextException::for($model::class);
    }

    /**
     * Permite sobrescribir en pruebas para simular un contexto HTTP.
     */
    protected function runningInConsole(): bool
    {
        return app()->runningInConsole();
    }

    private function isConsoleExempt(): bool
    {
        // Una petición HTTP nunca queda exenta, aunque se simule consola.
        if (app(TenantContext::class)->isHttpRequest()) {
            return false;
        }

        if (! $this->runningInConsole()) {
            return false;
        }

        if (! (bool) config('tenancy.allow_console_without_tenant', true)) {
            return false;
        }

        // Los workers de cola corren en consola pero NO quedan exentos:
        // deben fijar tenant explícito o usar withoutTenant($reason).
        if ($this->isQueueWorker()) {
            return false;
        }

        return true;
    }

    private function isQueueWorker(): bool
    {
        if (! isset($_SERVER['argv']) || ! is_array($_SERVER['argv'])) {
            return false;
        }

        foreach ($_SERVER['argv'] as $argument) {
            if (! is_string($argument)) {
                continue;
            }

            if (str_contains($argument, 'queue:work') || str_contains($argument, 'queue:listen') || str_contains($argument, 'queue:restart')) {
                return true;
            }
        }

        return false;
    }
}
