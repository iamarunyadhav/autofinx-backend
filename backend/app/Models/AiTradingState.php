<?php

namespace App\Models;

class AiTradingState extends BaseModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COOLING_DOWN = 'cooling_down';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_BLOCKED_BY_BREAKER = 'blocked_by_breaker';

    public const REASON_DAILY_LOSS_LIMIT = 'DAILY_LOSS_LIMIT';
    public const REASON_DRAWDOWN_LIMIT = 'DRAWDOWN_LIMIT';
    public const REASON_ORDER_FAILURE_STREAK = 'ORDER_FAILURE_STREAK';
    public const REASON_SLIPPAGE_SPIKE = 'SLIPPAGE_SPIKE';
    public const REASON_RECONCILIATION_MISMATCH = 'RECONCILIATION_MISMATCH';
    public const REASON_STALE_FEED = 'STALE_FEED';
    public const REASON_EXCHANGE_UNHEALTHY = 'EXCHANGE_UNHEALTHY';
    public const REASON_KILL_SWITCH = 'KILL_SWITCH';
    public const REASON_MANUAL_OPERATOR_PAUSE = 'MANUAL_OPERATOR_PAUSE';
    public const REASON_LOW_CONFIDENCE_ENVIRONMENT = 'LOW_CONFIDENCE_ENVIRONMENT';
    public const REASON_DECISION_INTERVAL_ACTIVE = 'DECISION_INTERVAL_ACTIVE';
    public const REASON_DECISION_RATE_LIMIT = 'DECISION_RATE_LIMIT';
    public const REASON_TRADE_COOLDOWN = 'TRADE_COOLDOWN';
    public const REASON_LOSS_COOLDOWN = 'LOSS_COOLDOWN';

    protected $casts = [
        'pause_until' => 'datetime',
        'cooldown_until' => 'datetime',
        'manual_resume_required' => 'boolean',
        'last_cycle_at' => 'datetime',
        'last_trade_at' => 'datetime',
        'last_loss_at' => 'datetime',
        'last_order_failed_at' => 'datetime',
        'consecutive_order_failures' => 'integer',
        'last_slippage_pct' => 'decimal:4',
        'anomaly_snapshot_json' => 'array',
        'meta_json' => 'array',
    ];
}
