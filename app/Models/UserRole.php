<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserRole extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'role_id',
        'organization_id',
    ];

    protected $casts = [
        'organization_id' => 'string',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid7();
            }
        });

        static::created(function (UserRole $model): void {
            app(\App\Support\Tenancy\TenantContext::class)
                ->flushPermissionCache($model->user_id, $model->organization_id);
        });

        static::deleted(function (UserRole $model): void {
            app(\App\Support\Tenancy\TenantContext::class)
                ->flushPermissionCache($model->user_id, $model->organization_id);
        });
    }

    /**
     * @return array<string, class-string<Model>>
     */
    protected function tenantParentRelations(): array
    {
        return [
            'user_id' => User::class,
            'role_id' => Role::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
