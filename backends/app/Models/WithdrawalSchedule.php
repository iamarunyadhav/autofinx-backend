<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalSchedule extends BaseModel
{
    protected $casts = [
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
        'hour' => 'integer',
        'minute' => 'integer',
        'amount_value' => 'decimal:8',
        'reserve_amount' => 'decimal:8',
        'min_payout_amount' => 'decimal:8',
        'is_active' => 'boolean',
        'destination_meta' => 'array',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

