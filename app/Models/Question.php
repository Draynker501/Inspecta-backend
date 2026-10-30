<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'checklist_section_id',
        'organization_id',
        'question_text',
        'description',
        'answer_type',
        'display_order',
        'required',
        'allows_evidence',
        'status',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'required' => 'boolean',
        'allows_evidence' => 'boolean',
        'status' => 'boolean',
        'organization_id' => 'string',
    ];

    /**
     * @return array<string, class-string<Model>>
     */
    protected function tenantParentRelations(): array
    {
        return [
            'checklist_section_id' => ChecklistSection::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function checklistSection(): BelongsTo
    {
        return $this->belongsTo(ChecklistSection::class, 'checklist_section_id');
    }

    public function normRequirements(): BelongsToMany
    {
        return $this->belongsToMany(
            NormRequirement::class,
            'question_norm_requirement',
            'question_id',
            'norm_requirement_id'
        );
    }

    public function inspectionAnswers(): HasMany
    {
        return $this->hasMany(InspectionAnswer::class, 'question_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'question_id');
    }
}
