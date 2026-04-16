<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionAttempt extends BaseModel
{
    protected $casts = [
        'request_json' => 'array',
        'response_json' => 'array',
        'latency_ms' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
