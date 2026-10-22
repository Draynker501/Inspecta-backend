<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'code',
        'tax_id',
        'address',
        'description',
        'phone',
        'email',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    protected $dates = ['deleted_at'];

    public function plants(): HasMany
    {
        return $this->hasMany(Plant::class, 'organization_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'organization_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'organization_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class, 'organization_id');
    }

    public function norms(): HasMany
    {
        return $this->hasMany(Norm::class, 'organization_id');
    }
}
