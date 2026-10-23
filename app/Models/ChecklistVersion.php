<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistVersion extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'checklist_id',
        'version',
        'status',
        'created_by',
        'published_at',
        'organization_id',
    ];

    protected $casts = [
        'status' => 'string',
        'published_at' => 'datetime',
        'organization_id' => 'string',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class, 'checklist_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ChecklistSection::class, 'checklist_version_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'checklist_version_id');
    }
}
