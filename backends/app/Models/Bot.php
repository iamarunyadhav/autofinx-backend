<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bot extends BaseModel
{
    public const ASSET_CLASS_CRYPTO = 'CRYPTO';
    public const ASSET_CLASS_METALS = 'METALS';

    protected $casts = [
        'is_live' => 'boolean',
        'strategy_params' => 'array',
        'allocation_percent' => 'decimal:4',
        'trade_allocation_amount' => 'decimal:8',
        'last_evaluated_at' => 'datetime',
        'last_started_at' => 'datetime',
        'cooldown_until' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ExchangeConnection::class, 'connection_id');
    }

    public function riskProfile(): BelongsTo
    {
        return $this->belongsTo(RiskProfile::class, 'risk_profile_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(TradeSignal::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BotLog::class);
    }

    public function isMetals(): bool
    {
        return strtoupper((string) $this->asset_class) === self::ASSET_CLASS_METALS;
    }
}
