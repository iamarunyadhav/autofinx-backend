<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalletSweepRule extends BaseModel
{
    protected $casts = [
        'min_amount' => 'decimal:8',
        'auto_withdraw_enabled' => 'boolean',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'meta_json' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WalletSweepRun::class, 'rule_id');
    }
}
