<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Observation-only, per-fill accuracy record for a Ratio Bot BUY/SELL.
 * See RatioBotTradeOutcomeService for the scoring definitions. No trading
 * path ever reads this model.
 */
class RatioBotTradeOutcome extends BaseModel
{
    public const SIDE_BUY = 'BUY';
    public const SIDE_SELL = 'SELL';

    public const STATUS_PENDING = 'pending';
    public const STATUS_EVALUATED = 'evaluated';
    public const STATUS_INSUFFICIENT_DATA = 'insufficient_data';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const VERDICT_BUY_RECOVERED = 'recovered_net_profitable';
    public const VERDICT_BUY_NOT_RECOVERED = 'not_recovered_within_horizon';
    public const VERDICT_SELL_WELL_TIMED = 'well_timed';
    public const VERDICT_SELL_EARLY = 'sold_early';

    protected $casts = [
        'executed_price' => 'decimal:10',
        'executed_qty' => 'decimal:10',
        'executed_at' => 'datetime',
        'cost_ratio' => 'decimal:10',
        'min_net_profit_ratio' => 'decimal:10',
        'required_exit_ratio' => 'decimal:10',
        'cost_basis_price' => 'decimal:10',
        'realized_net_ratio' => 'decimal:10',
        'net_profitable' => 'boolean',
        'horizon_seconds' => 'integer',
        'window_ends_at' => 'datetime',
        'evaluate_after' => 'datetime',
        'next_evaluation_at' => 'datetime',
        'decision_correct' => 'boolean',
        'best_price' => 'decimal:10',
        'worst_price' => 'decimal:10',
        'best_net_edge_ratio' => 'decimal:10',
        'max_adverse_ratio' => 'decimal:10',
        'missed_edge_ratio' => 'decimal:10',
        'avoided_drawdown_ratio' => 'decimal:10',
        'reached_floor_at' => 'datetime',
        'candles_observed' => 'integer',
        'candles_expected' => 'integer',
        'evaluation_attempts' => 'integer',
        'evaluated_at' => 'datetime',
        'stage_index' => 'integer',
        'cycle_number' => 'integer',
        'is_recovery_lot' => 'boolean',
        'protective_exit' => 'boolean',
        'metadata' => 'array',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(RatioBotDecision::class, 'ratio_bot_decision_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(RatioBotLot::class, 'ratio_bot_lot_id');
    }
}
