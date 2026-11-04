<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Membresía usuario–organización (M1.5, P1).
 *
 * No usa BelongsToOrganization ni OrganizationScope: el middleware la lee
 * antes de que exista contexto organizacional.
 */
class OrganizationMembership extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'organization_id',
        'status',
        'revoked_at',
    ];

    protected $casts = [
        'status' => 'string',
        'organization_id' => 'string',
        'user_id' => 'string',
        'revoked_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForOrganization($query, string $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public static function findActive(string $userId, string $organizationId): ?self
    {
        if ($userId === '' || $organizationId === '') {
            return null;
        }

        return static::query()
            ->forUser($userId)
            ->forOrganization($organizationId)
            ->active()
            ->first();
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid7();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
