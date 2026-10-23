<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionNormRequirement extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'question_id',
        'norm_requirement_id',
        'organization_id',
    ];

    protected $casts = [
        'organization_id' => 'string',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function normRequirement(): BelongsTo
    {
        return $this->belongsTo(NormRequirement::class, 'norm_requirement_id');
    }
}
