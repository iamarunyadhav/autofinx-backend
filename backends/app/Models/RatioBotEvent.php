<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatioBotEvent extends BaseModel
{
    protected $casts = [
        'context'     => 'array',
        'occurred_at' => 'datetime',
    ];

    public const EVENT_STARTED                = 'started';
    public const EVENT_PAUSED                 = 'paused';
    public const EVENT_RESUMED                = 'resumed';
    public const EVENT_STOPPED                = 'stopped';
    public const EVENT_BLOCKED                = 'blocked';
    public const EVENT_UNBLOCKED              = 'unblocked';
    public const EVENT_KILL_SWITCH            = 'kill_switch_activated';
    public const EVENT_COOLDOWN_ENTERED       = 'cooldown_entered';
    public const EVENT_COOLDOWN_EXITED        = 'cooldown_exited';
    public const EVENT_ORDER_FAILURE          = 'order_failure';
    public const EVENT_RECONCILIATION_MISMATCH = 'reconciliation_mismatch';
    public const EVENT_FEED_STALE             = 'feed_stale';
    public const EVENT_MANUAL_BUY_ANCHORED    = 'manual_buy_anchored';
    public const EVENT_MANUAL_SELL_ANCHORED   = 'manual_sell_anchored';
    public const EVENT_ERROR_REVIEW_REQUIRED  = 'error_review_required';
    public const EVENT_ORDER_FILLED           = 'order_filled';
    public const EVENT_FEED_HEALTH_CHANGED    = 'feed_health_changed';
    public const EVENT_SIGNAL_EMITTED         = 'signal_emitted';
    public const EVENT_RECONCILIATION_RUN     = 'reconciliation_run';

    // V2 (gated behind config.v2_enabled)
    public const EVENT_STAGED_SELL_FILLED           = 'staged_sell_filled';
    public const EVENT_RECOVERY_MONITORING_ENTERED  = 'recovery_monitoring_entered';
    public const EVENT_RECOVERY_REVIEW_COMPLETED    = 'recovery_review_completed';
    public const EVENT_STRATEGY_REANCHORED          = 'strategy_reanchored';
    public const EVENT_RECOVERY_TRADE_EXECUTED      = 'recovery_trade_executed';
    public const EVENT_RECOVERY_SLOT_TOPPED_UP      = 'recovery_slot_topped_up';
    public const EVENT_RECOVERY_SLOT_CAP_REACHED    = 'recovery_slot_cap_reached';
    public const EVENT_RECOVERY_SLOT_USED           = 'recovery_slot_used';

    // Diagnostics panel (operator-triggered, whitelisted remediation only)
    public const EVENT_DIAGNOSTICS_FIX_APPLIED      = 'diagnostics_fix_applied';

    // Futures Liquidation Guard
    public const EVENT_LIQUIDATION_GUARD_WATCH_ENTERED = 'liquidation_guard_watch_entered';
    public const EVENT_LIQUIDATION_GUARD_FORCE_EXIT    = 'liquidation_guard_force_exit';

    // Futures Part C -- exchange-side safety net
    public const EVENT_EXCHANGE_STOP_ORDER_PLACED     = 'exchange_stop_order_placed';
    public const EVENT_EXCHANGE_STOP_ORDER_REPLACED   = 'exchange_stop_order_replaced';
    public const EVENT_EXCHANGE_STOP_ORDER_CANCELLED  = 'exchange_stop_order_cancelled';
    public const EVENT_STOP_ORDER_SLIPPAGE_DETECTED   = 'stop_order_slippage_detected';
    public const EVENT_FORCE_EXIT_LOCK_CONTENDED      = 'force_exit_lock_contended';

    // Futures Part B -- futures-adapted recovery (single bounded top-up)
    public const EVENT_FUTURES_RECOVERY_TOPUP_EXECUTED = 'futures_recovery_topup_executed';
    public const EVENT_FUTURES_RECOVERY_PAUSED         = 'futures_recovery_paused';

    // RatioBrain -- orchestration facade (spot emergency guard + portfolio-
    // guarded controlled loss exit)
    public const EVENT_RATIO_BRAIN_EMERGENCY_ENTERED         = 'ratio_brain_emergency_entered';
    public const EVENT_RATIO_BRAIN_EMERGENCY_CLEARED         = 'ratio_brain_emergency_cleared';
    public const EVENT_RATIO_BRAIN_CONTROLLED_LOSS_EXIT      = 'ratio_brain_controlled_loss_exit';

    // Coin onboarding modes -- WATCH_ONLY needs no dedicated event (it is
    // provably the absence of the existing forced-anchor behavior); this one
    // covers SEED_POSITION's single deliberate initial buy.
    public const EVENT_SEED_POSITION_EXECUTED = 'seed_position_executed';

    // WalletAwareDirectionFallback -- see WalletAwareDirectionFallbackService.
    // Only fired when a wallet-insufficiency block on the brain/trend-resolved
    // direction is overridden in favor of the opposite, independently
    // market-justified, wallet-funded direction. Never fired for a confidence
    // or trend/forecast-disagreement block.
    public const EVENT_WALLET_FALLBACK_FLIP = 'wallet_aware_direction_flip';

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }
}
