<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectionAnswer extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'inspection_id',
        'question_id',
        'organization_id',
        'checklist_version_id',
        'checklist_section_id',
        'answer',
        'observation',
    ];

    protected $casts = [
        'organization_id' => 'string',
        'checklist_version_id' => 'string',
        'checklist_section_id' => 'string',
    ];

    /**
     * @return array<string, class-string<Model>>
     */
    protected function tenantParentRelations(): array
    {
        return [
            'inspection_id' => Inspection::class,
            'question_id' => Question::class,
            'checklist_version_id' => ChecklistVersion::class,
            'checklist_section_id' => ChecklistSection::class,
        ];
    }

    public function checklistVersion(): BelongsTo
    {
        return $this->belongsTo(ChecklistVersion::class, 'checklist_version_id');
    }

    public function checklistSection(): BelongsTo
    {
        return $this->belongsTo(ChecklistSection::class, 'checklist_section_id');
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

    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class, 'inspection_answer_id');
    }
}
