<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureTradeMissedSignalAudit extends BaseModel
{
    protected $table = 'future_trade_missed_signal_audits';

    protected $casts = [
        'signal_time' => 'datetime',
        'review_due_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'tp_hit_time' => 'datetime',
        'sl_hit_time' => 'datetime',
        'entry_reference_price' => 'decimal:8',
        'final_confidence' => 'decimal:4',
        'active_minimum' => 'decimal:4',
        'confidence_gap' => 'decimal:4',
        'core_entry_passed' => 'boolean',
        'soft_penalties_json' => 'array',
        'hard_blockers_json' => 'array',
        'component_scores_json' => 'array',
        'component_breakdown_json' => 'array',
        'indicator_influence_json' => 'array',
        'market_snapshot_json' => 'array',
        'mode_comparison_json' => 'array',
        'top_3_blockers' => 'array',
        'max_favorable_move_percent' => 'decimal:4',
        'max_adverse_move_percent' => 'decimal:4',
        'take_profit_price' => 'decimal:8',
        'stop_loss_price' => 'decimal:8',
        'would_hit_take_profit' => 'boolean',
        'would_hit_stop_loss' => 'boolean',
        'estimated_fee_percent' => 'decimal:4',
        'estimated_slippage_percent' => 'decimal:4',
        'gross_would_have_pnl_percent' => 'decimal:4',
        'net_would_have_pnl_percent' => 'decimal:4',
        'would_have_pnl_percent' => 'decimal:4',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(FutureTradeBotConfig::class, 'future_trade_bot_config_id');
    }
}
