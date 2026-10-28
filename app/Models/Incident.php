<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'inspection_id',
        'question_id',
        'reported_by',
        'plant_id',
        'area_id',
        'production_line_id',
        'organization_id',
        'type',
        'title',
        'description',
        'risk_level',
        'status',
        'latitude',
        'longitude',
        'location_description',
        'reported_at',
    ];

    protected $casts = [
        'status' => 'string',
        'risk_level' => 'string',
        'latitude' => 'decimal:10,7',
        'longitude' => 'decimal:10,7',
        'reported_at' => 'datetime',
        'organization_id' => 'string',
    ];

    /**
     * @return array<string, class-string<Model>>
     */
    protected function tenantParentRelations(): array
    {
        return [
            'inspection_id' => Inspection::class,
            'question_id' => Question::class,
            'reported_by' => User::class,
            'plant_id' => Plant::class,
            'area_id' => Area::class,
            'production_line_id' => ProductionLine::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class, 'inspection_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
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

    public function correctiveActions(): HasMany
    {
        return $this->hasMany(CorrectiveAction::class, 'incident_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class, 'incident_id');
    }
}
