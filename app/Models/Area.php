<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Area extends Model
{
    use SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'plant_id',
        'organization_id',
        'name',
        'code',
        'type',
        'description',
        'status',
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

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'plant_id');
    }

    public function productionLines(): HasMany
    {
        return $this->hasMany(ProductionLine::class, 'area_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'area_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'area_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_areas', 'area_id', 'user_id');
    }
}
