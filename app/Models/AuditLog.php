<?php

namespace App\Models;

use App\Exceptions\AuditLogImmutableException;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function boot(): void
    {
        parent::boot();

        // Append-only: update, delete, forceDelete y save sobre un registro
        // existente lanzan la excepcion dedicada.
        // Nota: UPDATE/DELETE masivos con query builder no pasan por eventos
        // del modelo; su proteccion son los permisos de la base de datos en
        // produccion.
        static::saving(function (self $model): void {
            if ($model->exists) {
                throw new AuditLogImmutableException();
            }
        });

        static::updating(function (self $model): void {
            throw new AuditLogImmutableException();
        });

        static::deleting(function (self $model): void {
            throw new AuditLogImmutableException();
        });
    }

    protected $fillable = [
        'id',
        'user_id',
        'organization_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'old_values' => 'collection',
        'new_values' => 'collection',
        'organization_id' => 'string',
    ];

    /**
     * @return array<string, class-string<Model>>
     */
    protected function tenantParentRelations(): array
    {
        // El actor se registra sin validar su membresía (D-11); la
        // integridad del usuario la garantiza la FK de la base de datos.
        return [];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
