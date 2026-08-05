<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureTradeAiDecision extends BaseModel
{
    protected $table = 'future_trade_ai_decisions';

    public const DECISION_CONFIRM = 'CONFIRM';
    public const DECISION_VETO = 'VETO';
    public const DECISION_RESIZE = 'RESIZE';
    public const DECISION_ERROR_FAIL_OPEN = 'ERROR_FAIL_OPEN';
    public const DECISION_ERROR_FAIL_CLOSED = 'ERROR_FAIL_CLOSED';
    public const DECISION_SKIPPED_CAP = 'SKIPPED_CAP';

    public const OUTCOME_WIN = 'win';
    public const OUTCOME_LOSS = 'loss';
    public const OUTCOME_BREAKEVEN = 'breakeven';

    protected $casts = [
        'signal_confidence' => 'decimal:4',
        'pine_score' => 'decimal:2',
        'ai_confidence' => 'decimal:4',
        'size_multiplier' => 'decimal:4',
        'sl_adjust_pct' => 'decimal:4',
        'tp_adjust_pct' => 'decimal:4',
        'risk_flags' => 'array',
        'context_json' => 'array',
        'executed' => 'boolean',
        'cost_usd' => 'decimal:6',
        'outcome_pnl' => 'decimal:10',
        'outcome_filled_at' => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(FutureTradeBotConfig::class, 'future_trade_bot_config_id');
    }

    public function logicalTrade(): BelongsTo
    {
        return $this->belongsTo(FutureTradeLogicalTrade::class, 'logical_trade_id');
    }

    public function isBlocking(): bool
    {
        return in_array($this->decision, [self::DECISION_VETO, self::DECISION_ERROR_FAIL_CLOSED], true);
    }
}
