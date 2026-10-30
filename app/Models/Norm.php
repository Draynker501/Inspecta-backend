<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Norm extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'code',
        'name',
        'description',
        'issuing_body',
        'version',
        'effective_date',
        'status',
        'organization_id',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'status' => 'boolean',
        'organization_id' => 'string',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function normRequirements(): HasMany
    {
        return $this->hasMany(NormRequirement::class, 'norm_id');
    }
}
