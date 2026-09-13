<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ForexGridBotConfig extends BaseModel
{
    protected $casts = [
        'enabled' => 'boolean',
        'grid_spacing_pips' => 'decimal:2',
        'base_lot_size' => 'decimal:4',
        'martingale_multiplier' => 'decimal:3',
        'martingale_max_lot_size' => 'decimal:4',
        'hybrid_martingale_level_count' => 'integer',
        'max_grid_levels' => 'integer',
        'max_total_exposure_lots' => 'decimal:4',
        'basket_take_profit_pct' => 'decimal:2',
        'basket_stop_loss_pct' => 'decimal:2',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ExchangeConnection::class, 'connection_id');
    }

    public function state(): HasOne
    {
        return $this->hasOne(ForexGridBotState::class, 'forex_grid_bot_config_id');
    }

    public function levels(): HasMany
    {
        return $this->hasMany(ForexGridBotLevel::class, 'forex_grid_bot_config_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ForexGridBotEvent::class, 'forex_grid_bot_config_id');
    }

    public function normalizedSymbol(): string
    {
        return strtoupper(trim((string) $this->symbol));
    }

    public function providerKey(): string
    {
        return strtolower(trim((string) ($this->provider ?: 'oanda')));
    }
}
