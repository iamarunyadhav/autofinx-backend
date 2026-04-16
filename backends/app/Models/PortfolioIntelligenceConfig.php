<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioIntelligenceConfig extends BaseModel
{
    protected $casts = [
        'enabled' => 'boolean',
        'opportunity_score_threshold' => 'decimal:4',
        'max_total_allocated_pct' => 'decimal:4',
        'max_symbol_allocated_pct' => 'decimal:4',
        'max_correlated_group_allocated_pct' => 'decimal:4',
        'max_simultaneous_new_trades' => 'integer',
        'reserve_cash_buffer_pct' => 'decimal:4',
        'max_open_positions' => 'integer',
        'max_open_decision_count' => 'integer',
        'max_total_notional' => 'decimal:8',
        'max_daily_loss_pct' => 'decimal:4',
        'max_drawdown_pct' => 'decimal:4',
        'prefer_hold_cash_under_high_uncertainty' => 'boolean',
        'reduce_size_under_external_risk' => 'boolean',
        'require_approval_for_rotation' => 'boolean',
        'enable_reduce_recommendations' => 'boolean',
        'enable_rotation_recommendations' => 'boolean',
        'max_universe_symbols' => 'integer',
        'score_weights_json' => 'array',
    ];

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'ranking_mode' => 'heuristic',
            'allocation_mode' => 'capped_score_weighted',
            'opportunity_score_threshold' => 65,
            'max_total_allocated_pct' => 35,
            'max_symbol_allocated_pct' => 8,
            'max_correlated_group_allocated_pct' => 15,
            'max_simultaneous_new_trades' => 3,
            'reserve_cash_buffer_pct' => 15,
            'max_open_positions' => 10,
            'max_open_decision_count' => 5,
            'max_total_notional' => null,
            'max_daily_loss_pct' => 3,
            'max_drawdown_pct' => 8,
            'prefer_hold_cash_under_high_uncertainty' => true,
            'reduce_size_under_external_risk' => true,
            'require_approval_for_rotation' => true,
            'enable_reduce_recommendations' => true,
            'enable_rotation_recommendations' => false,
            'max_universe_symbols' => 20,
            'score_weights_json' => null,
            'notes' => null,
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
