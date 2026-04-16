<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletSweepRun extends BaseModel
{
    protected $casts = [
        'amount' => 'decimal:8',
        'meta_json' => 'array',
        'swept_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(WalletSweepRule::class, 'rule_id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
