<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plant extends Model
{
    use SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'code',
        'address',
        'description',
        'status',
        'organization_id',
    ];

    protected $casts = [
        'status' => 'boolean',
        'organization_id' => 'string',
    ];

    protected $dates = ['deleted_at'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class, 'plant_id');
    }
}
