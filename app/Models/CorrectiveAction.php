<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorrectiveAction extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
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

    /**
     * @return array<string, class-string<Model>>
     */
    protected function tenantParentRelations(): array
    {
        return [
            'incident_id' => Incident::class,
            'assigned_to' => User::class,
        ];
    }

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
