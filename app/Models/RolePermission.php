<?php

namespace App\Models;

use App\Authorization\Permission;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Permiso concedido a un rol dentro de una organización (D-40).
 *
 * No hay tabla `permissions`: el catálogo vive en código (`Permission`).
 * Toda fila debe referenciar un permiso existente en el catálogo; la
 * validación se aplica al crear y al actualizar.
 */
class RolePermission extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'role_id',
        'organization_id',
        'permission',
    ];

    protected $casts = [
        'organization_id' => 'string',
        'role_id' => 'string',
        'permission' => 'string',
    ];

    protected static function booted(): void
    {
        static::creating(function (RolePermission $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid7();
            }

            self::assertPermissionInCatalog($model);
        });

        static::updating(function (RolePermission $model): void {
            self::assertPermissionInCatalog($model);
        });

        static::saved(function (RolePermission $model): void {
            app(TenantContext::class)->flushPermissionCache(null, $model->organization_id);
        });

        static::deleted(function (RolePermission $model): void {
            app(TenantContext::class)->flushPermissionCache(null, $model->organization_id);
        });
    }

    private static function assertPermissionInCatalog(RolePermission $model): void
    {
        if (! in_array($model->permission, Permission::values(), true)) {
            throw new InvalidArgumentException(
                "El permiso '{$model->permission}' no existe en el catálogo."
            );
        }
    }

    /**
     * @return array<string, class-string<Model>>
     */
    protected function tenantParentRelations(): array
    {
        return [
            'role_id' => Role::class,
        ];
    }

    public function organization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function role(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
