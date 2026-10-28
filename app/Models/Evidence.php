<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evidence extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'inspection_id',
        'inspection_answer_id',
        'incident_id',
        'uploaded_by',
        'organization_id',
        'file_name',
        'storage_path',
        'mime_type',
        'file_size',
        'description',
        'latitude',
        'longitude',
        'captured_at',
        'uploaded_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'latitude' => 'decimal:10,7',
        'longitude' => 'decimal:10,7',
        'captured_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'organization_id' => 'string',
    ];

    /**
     * @return array<string, class-string<Model>>
     */
    protected function tenantParentRelations(): array
    {
        return [
            'inspection_id' => Inspection::class,
            'inspection_answer_id' => InspectionAnswer::class,
            'incident_id' => Incident::class,
            'uploaded_by' => User::class,
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

    public function inspectionAnswer(): BelongsTo
    {
        return $this->belongsTo(InspectionAnswer::class, 'inspection_answer_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
