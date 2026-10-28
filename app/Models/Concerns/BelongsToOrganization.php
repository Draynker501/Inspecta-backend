<?php

namespace App\Models\Concerns;

use App\Exceptions\CrossTenantOperationException;
use App\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Aísla un modelo por organización.
 *
 * - Lecturas: global scope por organization_id cuando hay contexto.
 * - Creación: toma organization_id del contexto y rechaza uno distinto.
 * - Actualización: impide mover el registro a otra organización.
 * - Borrado: rechaza borrar un registro de otra organización.
 * - Jerarquía: valida que las FK declaradas en tenantParentRelations()
 *   apunten a registros de la misma organización.
 *
 * Sin contexto (consola, seeders, factories y jobs) no se filtra, pero
 * sigue siendo obligatorio que organization_id exista y que las FK
 * declaradas sean coherentes.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            $model->assertTenantOnCreate();
            $model->assertTenantParents();
        });

        static::updating(function (Model $model): void {
            $model->assertTenantOnUpdate();
            $model->assertTenantParents();
        });

        static::deleting(function (Model $model): void {
            $model->assertTenantOnDelete();
        });
    }

    /**
     * Relaciones padre que deben pertenecer a la misma organización.
     *
     * @return array<string, class-string<Model>>
     */
    protected function tenantParentRelations(): array
    {
        return [];
    }

    protected function assertTenantOnCreate(): void
    {
        $contextId = app(TenantContext::class)->id();
        $organizationId = $this->getAttribute('organization_id');

        if ($contextId !== null) {
            if ($organizationId !== null && $organizationId !== $contextId) {
                throw CrossTenantOperationException::write();
            }

            $this->setAttribute('organization_id', $contextId);
        }

        if (blank($this->getAttribute('organization_id'))) {
            throw MissingTenantContextException::for(static::class);
        }
    }

    protected function assertTenantOnUpdate(): void
    {
        $contextId = app(TenantContext::class)->id();
        $organizationId = $this->getAttribute('organization_id');

        if (blank($organizationId)) {
            throw MissingTenantContextException::for(static::class);
        }

        // Con contexto, un registro solo puede permanecer en su organización.
        // El cambio explícito entre organizaciones se reserva a consola/jobs
        // auditables (sin contexto), nunca a una petición HTTP.
        if ($this->isDirty('organization_id')
            && $contextId !== null
            && $organizationId !== $contextId) {
            throw CrossTenantOperationException::write();
        }
    }

    protected function assertTenantOnDelete(): void
    {
        $contextId = app(TenantContext::class)->id();

        if ($contextId !== null && $this->getAttribute('organization_id') !== $contextId) {
            throw CrossTenantOperationException::write();
        }
    }

    protected function assertTenantParents(): void
    {
        $organizationId = $this->getAttribute('organization_id');

        foreach ($this->tenantParentRelations() as $foreignKey => $parentClass) {
            $parentId = $this->getAttribute($foreignKey);

            if ($parentId === null) {
                continue;
            }

            $parent = new $parentClass;

            $exists = $parentClass::query()
                ->whereKey($parentId)
                ->where($parent->qualifyColumn('organization_id'), $organizationId)
                ->exists();

            if (! $exists) {
                throw CrossTenantOperationException::relation($foreignKey);
            }
        }
    }
}
