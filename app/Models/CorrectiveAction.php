<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorrectiveAction extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'incident_id',
        'assigned_to',
        'organization_id',
        'description',
        'priority',
        'due_date',
        'status',
        'observations',
        'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'status' => 'string',
        'priority' => 'string',
        'completed_at' => 'datetime',
        'organization_id' => 'string',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(CorrectiveActionUpdate::class, 'corrective_action_id');
    }
}
