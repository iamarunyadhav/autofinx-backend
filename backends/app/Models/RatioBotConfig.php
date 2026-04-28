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
        'base_quote_amount'           => 'decimal:8',
        'base_asset_budget'           => 'decimal:8',
        'quote_asset_budget'          => 'decimal:8',
        'max_trade_size_pct'          => 'decimal:4',
        'reserve_cash_buffer_pct'     => 'decimal:4',
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
    ];

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

    public function timeFrame(): string
    {
        return strtolower(trim((string) ($this->timeframe ?: '1m')));
    }
}
