<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistSection extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'checklist_version_id',
        'organization_id',
        'name',
        'description',
        'display_order',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'organization_id' => 'string',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function checklistVersion(): BelongsTo
    {
        return $this->belongsTo(ChecklistVersion::class, 'checklist_version_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'checklist_section_id');
    }
}
