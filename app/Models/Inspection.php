<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inspection extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'supervisor_id',
        'checklist_version_id',
        'plant_id',
        'area_id',
        'production_line_id',
        'organization_id',
        'status',
        'started_at',
        'completed_at',
        'supervisor_confirmed_at',
        'rejected_at',
        'supervisor_observations',
        'latitude',
        'longitude',
        'location_description',
        'observations',
    ];

    protected $casts = [
        'status' => 'string',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'supervisor_confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'latitude' => 'decimal:10,7',
        'longitude' => 'decimal:10,7',
        'organization_id' => 'string',
    ];

    /**
     * @return array<string, class-string<Model>>
     */
    protected function tenantParentRelations(): array
    {
        return [
            'user_id' => User::class,
            'supervisor_id' => User::class,
            'checklist_version_id' => ChecklistVersion::class,
            'plant_id' => Plant::class,
            'area_id' => Area::class,
            'production_line_id' => ProductionLine::class,
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

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function checklistVersion(): BelongsTo
    {
        return $this->belongsTo(ChecklistVersion::class, 'checklist_version_id');
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'plant_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    public function productionLine(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class, 'production_line_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(InspectionAnswer::class, 'inspection_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'inspection_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class, 'inspection_id');
    }
}
