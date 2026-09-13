<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PepperstoneBotConfig extends BaseModel
{
    protected $casts = [
        'enabled' => 'boolean',
        'base_lot_size' => 'decimal:4',
        'lot_per_level' => 'decimal:4',
        'martingale_multiplier' => 'decimal:3',
        'martingale_max_lot_size' => 'decimal:4',
        'hybrid_martingale_level_count' => 'integer',
        'max_grid_levels' => 'integer',
        'max_basket_levels' => 'integer',
        'max_total_exposure_lots' => 'decimal:4',
        'max_position_lots' => 'decimal:4',
        'portfolio_exposure_cap_lots' => 'decimal:4',
        'spread_limit_pips' => 'decimal:2',
        'slippage_limit_pips' => 'decimal:2',
        'maximum_allowed_slippage' => 'decimal:4',
        'v3_confidence_threshold' => 'decimal:4',
        'min_mtf_confidence' => 'decimal:4',
        'daily_loss_limit_pct' => 'decimal:2',
        'margin_safety_reserve_floor' => 'decimal:4',
        'basket_take_profit_pct' => 'decimal:2',
        'basket_stop_loss_pct' => 'decimal:2',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ExchangeConnection::class, 'connection_id');
    }

    public function state(): HasOne
    {
        return $this->hasOne(PepperstoneBotState::class, 'pepperstone_bot_config_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(PepperstonePosition::class, 'pepperstone_bot_config_id');
    }

    /**
     * The basket model supersedes pepperstone_positions for all new
     * trading -- see database/migrations/2026_09_13_000000_pepperstone_
     * mtf_basket_upgrade.php's docblock for why the old table/relation
     * above is left in place, unused, rather than removed.
     */
    public function baskets(): HasMany
    {
        return $this->hasMany(PepperstoneBasket::class, 'pepperstone_bot_config_id');
    }

    public function activeBasket(): ?PepperstoneBasket
    {
        return $this->baskets()->whereIn('status', PepperstoneBasket::OPEN_STATUSES)->first();
    }

    public function events(): HasMany
    {
        return $this->hasMany(PepperstoneBotEvent::class, 'pepperstone_bot_config_id');
    }

    public function providerKey(): string
    {
        return 'pepperstone';
    }
}
