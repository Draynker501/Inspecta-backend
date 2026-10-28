<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra las consultas por la organización activa.
 *
 * Sin contexto (consola, seeders, factories y jobs) no aplica filtro:
 * esas rutas de ejecución deben pasar organization_id explícitamente o
 * envolver su trabajo en TenantContext::runAs().
 */
final class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = app(TenantContext::class)->id();

        if ($organizationId !== null) {
            $builder->where($model->qualifyColumn('organization_id'), $organizationId);
        }
    }
}
