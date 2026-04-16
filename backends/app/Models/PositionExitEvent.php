<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionExitEvent extends BaseModel
{
    protected $casts = [
        'payload_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function state(): BelongsTo
    {
        return $this->belongsTo(PositionExitState::class, 'position_exit_state_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
