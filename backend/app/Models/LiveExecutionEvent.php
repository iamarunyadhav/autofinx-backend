<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveExecutionEvent extends BaseModel
{
    protected $casts = [
        'payload_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
