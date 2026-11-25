<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

class Role extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'description',
        'status',
        'organization_id',
    ];

    protected $casts = [
        'status' => 'boolean',
        'system_key' => 'string',
        'organization_id' => 'string',
    ];

    protected static function booted(): void
    {
        static::updating(function (Role $role): void {
            if ($role->isDirty('system_key')) {
                throw new LogicException(
                    'system_key no puede modificarse mediante operaciones ordinarias.'
                );
            }
        });

        static::updated(function (Role $role): void {
            if ($role->wasChanged('status')) {
                app(TenantContext::class)
                    ->flushPermissionCache(
                        null,
                        $role->organization_id
                    );
            }
        });

        static::deleted(function (Role $role): void {
            app(TenantContext::class)
                ->flushPermissionCache(
                    null,
                    $role->organization_id
                );
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(
            Organization::class,
            'organization_id'
        );
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_roles',
            'role_id',
            'user_id'
        );
    }
}
