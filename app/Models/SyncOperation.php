<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncOperation extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'operation_id',
        'device_id',
        'resource',
        'resource_id',
        'operation',
        'status',
        'payload',
        'server_state',
        'errors',
    ];

    protected $casts = [
        'payload' => 'collection',
        'server_state' => 'collection',
        'errors' => 'collection',
        'created_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
