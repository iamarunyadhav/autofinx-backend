<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioIntelligenceRun extends BaseModel
{
    protected $casts = [
        'symbol_universe_json' => 'array',
        'portfolio_context_json' => 'array',
        'ranked_opportunities_json' => 'array',
        'allocation_json' => 'array',
        'risk_budget_json' => 'array',
        'recommendations_json' => 'array',
        'selection_summary_json' => 'array',
        'config_snapshot_json' => 'array',
        'external_context_json' => 'array',
        'equity_estimate' => 'decimal:8',
        'allocated_capital' => 'decimal:8',
        'free_capital' => 'decimal:8',
        'selected_count' => 'integer',
        'blocked_count' => 'integer',
        'paused_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(PortfolioIntelligenceConfig::class, 'portfolio_intelligence_config_id');
    }
}
