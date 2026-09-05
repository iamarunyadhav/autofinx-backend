<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RatioBotConfig extends BaseModel
{
    protected $casts = [
        'enabled'                     => 'boolean',
        'kill_switch'                => 'boolean',
        'brain_enabled'               => 'boolean',
        'brain_min_confidence'        => 'integer',
        'base_quote_amount'           => 'decimal:8',
        'initial_entry_pct'           => 'decimal:4',
        'reserve_tranche_count'       => 'integer',
        'base_asset_budget'           => 'decimal:8',
        'quote_asset_budget'          => 'decimal:8',
        'max_trade_size_pct'          => 'decimal:4',
        'reserve_cash_buffer_pct'     => 'decimal:4',
        'strategy_reserve_pct'        => 'decimal:4',
        'stage_buy_allocations'       => 'array',
        'stage_buy_drops'             => 'array',
        'stage_sell_allocations'      => 'array',
        'stage_sell_rises'            => 'array',
        'sell_threshold_ratio'        => 'decimal:6',
        'buy_threshold_ratio'         => 'decimal:6',
        'allow_partial_sell'          => 'boolean',
        'allow_partial_buy'           => 'boolean',
        'partial_sell_pct'            => 'decimal:4',
        'partial_buy_pct'             => 'decimal:4',
        'poll_interval_seconds'       => 'integer',
        'cooldown_seconds_after_trade'=> 'integer',
        'fee_bps'                     => 'decimal:4',
        'max_slippage_bps'            => 'decimal:4',
        'max_allowed_slippage_bps'     => 'decimal:4',
        'min_net_profit_bps'          => 'decimal:4',
        'stale_feed_max_age_seconds'  => 'integer',
        'min_seconds_between_evaluations' => 'integer',
        'use_advisory_filter'         => 'boolean',
        'selling_loss_ratio'          => 'decimal:6',
        'buying_loss_ratio'           => 'decimal:6',
        'max_trades_per_hour'         => 'integer',
        'max_trades_per_day'          => 'integer',
        'trigger_confirm_seconds'     => 'integer',
        'trigger_confirm_updates'     => 'integer',
        'use_candle_close_confirmation' => 'boolean',
        'min_price_stability_seconds' => 'integer',
        'max_spike_deviation_bps'     => 'decimal:4',
        'daily_loss_limit_pct'        => 'decimal:4',
        'max_drawdown_pct'            => 'decimal:4',
        'max_open_orders'             => 'integer',
        'max_position_pct'            => 'decimal:4',
        'kill_switch_enabled'         => 'boolean',
        'pause_on_order_failure_streak' => 'integer',
        'pause_on_slippage_spike'     => 'boolean',
        'pause_on_exchange_unhealthy' => 'boolean',
        'pause_on_stale_feed'         => 'boolean',
        'require_stop_loss'           => 'boolean',
        'require_take_profit'         => 'boolean',
        'require_time_stop'           => 'boolean',
        'stop_loss_pct'               => 'decimal:4',
        'take_profit_pct'             => 'decimal:4',
        'time_stop_minutes'           => 'integer',
        'strategy_params'             => 'array',
        'stale_feed_timeout_seconds'  => 'integer',
        'leverage'                    => 'decimal:4',
        'reduce_only_exit_orders'     => 'boolean',
        'max_open_position_per_symbol'=> 'decimal:8',
        'allow_reverse_signal'        => 'boolean',
        'close_on_opposite_signal'    => 'boolean',
        'risk_per_trade'              => 'decimal:4',
        'max_daily_loss'              => 'decimal:8',
        'max_consecutive_losses'      => 'integer',
        'max_entry_price_drift_bps'   => 'decimal:4',
        'v2_enabled'                  => 'boolean',
        'sell_tranche_count'          => 'integer',
        'initial_exit_pct'            => 'decimal:4',
        'reserve_min_pct'             => 'decimal:4',
        'reserve_max_pct'             => 'decimal:4',
        'recovery_enabled'            => 'boolean',
        'recovery_timeout_minutes'    => 'integer',
        'recovery_buy_drop_pct'       => 'decimal:4',
        'recovery_sell_rise_pct'      => 'decimal:4',
        'no_trade_alert_minutes'      => 'integer',
    ];

    public function spotExecutionRoute(): string
    {
        $route = strtolower(trim((string) ($this->spot_execution_route ?: config('services.binance.ratio_bot_spot_execution_route', 'spot_market'))));

        return $route === 'convert' ? 'convert' : 'spot_market';
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function state(): HasOne
    {
        return $this->hasOne(RatioBotState::class, 'ratio_bot_config_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(RatioBotDecision::class, 'ratio_bot_config_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RatioBotEvent::class, 'ratio_bot_config_id');
    }

    public function reconciliationLogs(): HasMany
    {
        return $this->hasMany(RatioBotReconciliationLog::class, 'ratio_bot_config_id');
    }

    public function brainSnapshots(): HasMany
    {
        return $this->hasMany(RatioBotBrainSnapshot::class, 'ratio_bot_config_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(RatioBotLot::class, 'ratio_bot_config_id');
    }

    public function isLiveMode(): bool
    {
        return $this->execution_mode === 'live';
    }

    public function isAutoMode(): bool
    {
        return $this->mode === 'restricted_auto';
    }

    public function isSandbox(): bool
    {
        return $this->execution_mode === 'sandbox';
    }

    public function normalizedSymbol(): string
    {
        return strtoupper(trim((string) $this->symbol));
    }

    public function exchangeKey(): string
    {
        $exchange = strtolower(trim((string) ($this->exchange ?: 'binance')));

        return $this->marketType() === 'futures'
            ? "{$exchange}_futures"
            : $exchange;
    }

    public function marketType(): string
    {
        return strtolower(trim((string) ($this->market_type ?: 'spot')));
    }

    public function usesTrailingRatio(): bool
    {
        return in_array((string) $this->trigger_mode, ['trailing_ratio', 'trailing'], true)
            || strtolower((string) $this->algorithm) === 'trailing_ratio';
    }

    public function algorithmKey(): string
    {
        $algorithm = strtolower(trim((string) ($this->algorithm ?: '')));
        if ($algorithm !== '' && ! ($algorithm === 'ratio_threshold' && $this->usesTrailingRatio())) {
            return $algorithm;
        }

        return $this->usesTrailingRatio() ? 'trailing_ratio' : 'ratio_threshold';
    }

    public function evaluationModeKey(): string
    {
        return strtolower(trim((string) ($this->evaluation_mode ?: 'polling')));
    }

    public function usesRealtimeFeed(): bool
    {
        return in_array($this->evaluationModeKey(), ['websocket', 'hybrid', 'tick', 'candle_close'], true);
    }

    public function evaluatesOnTick(): bool
    {
        return in_array($this->evaluationModeKey(), ['websocket', 'tick', 'hybrid'], true);
    }

    public function evaluatesOnCandleClose(): bool
    {
        return in_array($this->evaluationModeKey(), ['candle_close', 'hybrid'], true);
    }

    public function staleFeedWindowSeconds(): int
    {
        return max(1, (int) ($this->stale_feed_timeout_seconds ?? $this->stale_feed_max_age_seconds ?? 30));
    }

    public function stagePlanEnabled(): bool
    {
        return (bool) $this->v2_enabled
            && $this->marketType() === 'spot'
            && $this->algorithmKey() === 'ratio_threshold'
            && is_array($this->stage_buy_allocations)
            && count($this->stage_buy_allocations) > 0;
    }

    public function timeFrame(): string
    {
        return strtolower(trim((string) ($this->timeframe ?: '1m')));
    }

    /**
     * Dynamic reserve tranches (Phase 2) — spot + ratio_threshold only.
     * Trailing/RSI/MA/breakout algorithms keep their own arm/peak/trough
     * state, which doesn't cleanly combine with tranche accumulation.
     */
    public function reserveEnabled(): bool
    {
        return $this->marketType() === 'spot'
            && $this->algorithmKey() === 'ratio_threshold'
            && (float) ($this->initial_entry_pct ?? 1.0) < 1.0
            && (int) ($this->reserve_tranche_count ?? 0) > 0;
    }

    /**
     * Pre-submit price-drift guard threshold (bps). 0 disables the check for
     * this bot. Default mirrors the migration default (50 bps / 0.5%).
     */
    public function maxEntryPriceDriftBps(): float
    {
        return max(0.0, (float) ($this->max_entry_price_drift_bps ?? 50.0));
    }

    public function maxAllowedSlippageBps(): float
    {
        return max(0.0, (float) ($this->max_allowed_slippage_bps ?? $this->max_slippage_bps ?? 0.0));
    }

    /**
     * Staged SELL (V2) — spot + ratio_threshold only, mirrors reserveEnabled()'s
     * exact gating shape. Inert unless the bot has explicitly opted into v2_enabled.
     */
    public function sellTranchesEnabled(): bool
    {
        return (bool) $this->v2_enabled
            && $this->marketType() === 'spot'
            && $this->algorithmKey() === 'ratio_threshold'
            && (float) ($this->initial_exit_pct ?? 1.0) < 1.0
            && (int) ($this->sell_tranche_count ?? 0) > 0;
    }

    /**
     * Recovery/re-anchor monitoring (V2) — requires an explicit opt-in on
     * top of v2_enabled, a configured timeout, spot + ratio_threshold only
     * (mirrors reserveEnabled()'s scope — trailing/RSI/MA/breakout algorithms
     * keep their own arm/peak/trough state, not accounted for by the
     * recovery evaluation branches).
     */
    public function recoveryEnabled(): bool
    {
        return (bool) $this->v2_enabled
            && (bool) $this->recovery_enabled
            && (int) ($this->recovery_timeout_minutes ?? 0) > 0
            && $this->marketType() === 'spot'
            && $this->algorithmKey() === 'ratio_threshold';
    }

    /**
     * The Stage 4 Recovery Reserve — the slice of strategy_reserve_pct that
     * stage_buy_allocations never deploys during normal Stage 1-3 trading.
     * Only meaningful when recoveryEnabled() and stagePlanEnabled() are both
     * true; deploying it is otherwise a no-op (see RatioBotDecisionService).
     */
    public function recoveryReservePct(): float
    {
        return max(0.0, min(100.0, (float) ($this->strategy_reserve_pct ?? 0)));
    }

    public function recoveryBuyDropRatio(): float
    {
        return max(0.0001, (float) ($this->recovery_buy_drop_pct ?? 5.0)) / 100;
    }

    public function recoverySellRiseRatio(): float
    {
        return max(0.0001, (float) ($this->recovery_sell_rise_pct ?? 2.0)) / 100;
    }

    public function startingEntry(): ?string
    {
        $value = strtoupper(trim((string) ($this->starting_entry ?? '')));

        return in_array($value, ['BUY', 'SELL'], true) ? $value : null;
    }

    /**
     * @return array{0: ?string, 1: ?string} [base, quote], e.g. ["BTC", "USDT"]
     */
    public function splitSymbolAssets(): array
    {
        $symbol = $this->normalizedSymbol();
        foreach (['USDT', 'USDC', 'BUSD', 'USD', 'BTC', 'ETH', 'EUR', 'LKR'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                return [substr($symbol, 0, -strlen($quote)), $quote];
            }
        }

        return [null, null];
    }

    public function baseAsset(): ?string
    {
        return $this->splitSymbolAssets()[0];
    }

    public function quoteAsset(): ?string
    {
        return $this->splitSymbolAssets()[1];
    }
}
