<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDecisionCycle extends BaseModel
{
    public const STATUS_STARTED = 'started';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_NO_TRADE = 'no_trade';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_FAILED = 'failed';

    protected $casts = [
        'context_summary_json' => 'array',
        'validation_result_json' => 'array',
        'policy_result_json' => 'array',
        'anomalies_json' => 'array',
        'state_snapshot_json' => 'array',
        'execution_result_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    public function decision(): BelongsTo
    {
        return $this->belongsTo(AiDecision::class, 'ai_decision_id');
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(AiTradingConfig::class, 'ai_trading_config_id');
    }
}
