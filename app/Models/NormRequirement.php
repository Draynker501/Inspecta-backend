<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NormRequirement extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'norm_id',
        'organization_id',
        'code',
        'section',
        'description',
    ];

    protected $casts = [
        'organization_id' => 'string',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function norm(): BelongsTo
    {
        return $this->belongsTo(Norm::class, 'norm_id');
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(
            Question::class,
            'question_norm_requirement',
            'norm_requirement_id',
            'question_id'
        );
    }
}
