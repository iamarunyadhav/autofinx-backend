<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Manages all state transitions for the ratio bot.
 *
 * Single responsibility: update ratio_bot_states + emit ratio_bot_events.
 * Does NOT execute orders.
 */
class RatioBotStateService
{
    private const SELL_DUST_EPSILON = 0.00000001;

    /**
     * Ensure the config has a state row; return it.
     */
    public function getOrCreate(RatioBotConfig $config): RatioBotState
    {
        if ($config->state) {
            return $config->state;
        }

        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state'      => RatioBotState::STATE_IDLE,
        ]);

        // Without this, a $config instance whose `state` relation was already
        // resolved to null (e.g. loaded via with('state') before this row
        // existed) keeps returning null from $config->state afterward, and a
        // second getOrCreate() call on that same instance would try to
        // RatioBotState::create() again and hit the unique constraint on
        // ratio_bot_config_id.
        $config->setRelation('state', $state);

        return $state;
    }

    /**
     * Operator manually records an initial (or forced) buy anchor.
     * Transitions the bot from idle/flat → holding.
     */
    public function anchorManualBuy(
        RatioBotConfig $config,
        float $buyPrice,
        float $quantity,
        ?string $orderId = null,
        ?string $userId = null
    ): RatioBotState {
        $state = $this->getOrCreate($config);

        $state->update([
            'position_state'      => RatioBotState::STATE_HOLDING,
            'last_buy_price'      => $buyPrice,
            'last_buy_quantity'   => $quantity,
            // Manual anchor is an operator override outside the reserve
            // tranche system — always starts fresh, never inherits a
            // stale reserve from whatever cycle preceded it.
            'reserve_tranches_remaining' => 0,
            'reserve_quote_remaining' => 0,
            'last_trade_side'     => 'BUY',
            'last_trade_time'     => now(),
            'active_anchor_price' => $buyPrice,
            'active_anchor_type'  => 'buy_anchor',
            'last_executed_order_id' => $orderId,
            'last_executed_client_order_id' => null,
            'block_reason_code'   => null,
            'block_reason_detail' => null,
            'cooldown_until'      => null,
            'awaiting_since'      => null,
            'recovery_action_required' => false,
            'sell_armed'          => false,
            'sell_peak_ratio'     => null,
            'sell_peak_price'     => null,
            'buy_armed'           => false,
            'buy_trough_ratio'    => null,
            'buy_trough_price'    => null,
            'pending_trigger_side' => null,
            'pending_trigger_started_at' => null,
            'pending_trigger_updates' => 0,
            'pending_trigger_ratio' => null,
            'pending_trigger_price' => null,
            'last_active_idempotency_key' => null,
            'last_unresolved_order_id' => null,
            'last_unresolved_client_order_id' => null,
            'last_position_side' => 'long',
            'last_position_qty' => $quantity,
            'entry_price' => $buyPrice,
            'unrealized_pnl' => 0,
        ]);

        $this->emitEvent($config, RatioBotEvent::EVENT_MANUAL_BUY_ANCHORED, [
            'buy_price' => $buyPrice,
            'quantity'  => $quantity,
            'order_id'  => $orderId,
        ], $userId);

        Log::info('[RatioBot] Manual buy anchored', ['config_id' => $config->id, 'price' => $buyPrice]);

        return $state->refresh();
    }

    /**
     * Operator manually records a sell anchor.
     * Transitions the bot to flat so the next automatic action can only be BUY.
     */
    public function anchorManualSell(
        RatioBotConfig $config,
        float $sellPrice,
        float $quantity,
        ?string $orderId = null,
        ?string $userId = null
    ): RatioBotState {
        $state = $this->getOrCreate($config);

        $state->update([
            'position_state'      => RatioBotState::STATE_FLAT,
            'last_sell_price'     => $sellPrice,
            'last_sell_quantity'  => $quantity,
            'reserve_tranches_remaining' => 0,
            'reserve_quote_remaining' => 0,
            'last_trade_side'     => 'SELL',
            'last_trade_time'     => now(),
            'active_anchor_price' => $sellPrice,
            'active_anchor_type'  => 'sell_anchor',
            'last_executed_order_id' => $orderId,
            'last_executed_client_order_id' => null,
            'block_reason_code'   => null,
            'block_reason_detail' => null,
            'cooldown_until'      => null,
            'awaiting_since'      => null,
            'recovery_action_required' => false,
            'sell_armed'          => false,
            'sell_peak_ratio'     => null,
            'sell_peak_price'     => null,
            'buy_armed'           => false,
            'buy_trough_ratio'    => null,
            'buy_trough_price'    => null,
            'pending_trigger_side' => null,
            'pending_trigger_started_at' => null,
            'pending_trigger_updates' => 0,
            'pending_trigger_ratio' => null,
            'pending_trigger_price' => null,
            'last_active_idempotency_key' => null,
            'last_unresolved_order_id' => null,
            'last_unresolved_client_order_id' => null,
            'last_position_side' => 'flat',
            'last_position_qty' => 0,
            'entry_price' => null,
            'unrealized_pnl' => 0,
        ]);

        $this->emitEvent($config, RatioBotEvent::EVENT_MANUAL_SELL_ANCHORED, [
            'sell_price' => $sellPrice,
            'quantity' => $quantity,
            'order_id' => $orderId,
        ], $userId);

        Log::info('[RatioBot] Manual sell anchored', ['config_id' => $config->id, 'price' => $sellPrice]);

        return $state->refresh();
    }

    /**
     * After a successful BUY execution.
     * Transitions: flat → holding.
     */
    public function onBuyFilled(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        float $executedPrice,
        float $executedQty,
        string $orderId,
        string $clientOrderId
    ): RatioBotState {
        $state = $this->getOrCreate($config);
        $cooldownUntil = now()->addSeconds((int) $config->cooldown_seconds_after_trade);
        $previousTracking = $this->trackingSnapshot($state);

        // Reserve tranches (Phase 2): a fill on top of an already-open
        // PARTIAL_HOLDING position accumulates into a weighted-average
        // entry instead of overwriting the anchor. A fill from FLAT/IDLE
        // is always a fresh entry, regardless of leftover reserve fields.
        $isTrancheFill = $state->isPartialHolding();
        $priorQty = (float) ($state->last_buy_quantity ?? 0);
        $priorAvg = (float) ($state->last_buy_price ?? 0);

        if ($isTrancheFill && $priorQty > 0) {
            $newQty = $priorQty + $executedQty;
            $newAvgPrice = (($priorQty * $priorAvg) + ($executedQty * $executedPrice)) / $newQty;
            $tranchesRemaining = max(0, (int) ($state->reserve_tranches_remaining ?? 0) - 1);
            $quoteRemaining = max(0.0, (float) ($state->reserve_quote_remaining ?? 0) - ($executedQty * $executedPrice));
            $nextPositionState = $tranchesRemaining > 0 ? RatioBotState::STATE_PARTIAL_HOLDING : RatioBotState::STATE_HOLDING;
        } else {
            $newQty = $executedQty;
            $newAvgPrice = $executedPrice;
            $reserveEnabled = $config->reserveEnabled();
            $tranchesRemaining = $reserveEnabled ? (int) $config->reserve_tranche_count : 0;
            // Derived from the ACTUAL spend rather than the configured
            // initial_entry_pct, so this is automatically correct whether
            // that entry was sized statically or by the (V2) dynamic
            // reserve manager — no new dependency introduced here.
            $quoteRemaining = $reserveEnabled
                ? max(0.0, (float) $config->base_quote_amount - ($executedQty * $executedPrice))
                : 0.0;
            $nextPositionState = $reserveEnabled ? RatioBotState::STATE_PARTIAL_HOLDING : RatioBotState::STATE_HOLDING;
        }

        $state->update([
            'position_state'                  => $nextPositionState,
            'last_buy_price'                   => $newAvgPrice,
            'last_buy_quantity'                => $newQty,
            'reserve_tranches_remaining'       => $tranchesRemaining,
            'reserve_quote_remaining'          => $quoteRemaining,
            'last_trade_side'                 => 'BUY',
            'last_trade_time'                 => now(),
            'active_anchor_price'             => $newAvgPrice,
            'active_anchor_type'              => 'buy_anchor',
            'last_executed_order_id'          => $orderId,
            'last_executed_client_order_id'   => $clientOrderId,
            'consecutive_order_failures'      => 0,
            'cooldown_until'                  => $cooldownUntil,
            'block_reason_code'               => null,
            'block_reason_detail'             => null,
            'awaiting_since'                  => null,
            'recovery_action_required'         => false,
            'sell_armed'                       => false,
            'sell_peak_ratio'                  => null,
            'sell_peak_price'                  => null,
            'buy_armed'                        => false,
            'buy_trough_ratio'                 => null,
            'buy_trough_price'                 => null,
            'pending_trigger_side'             => null,
            'pending_trigger_started_at'       => null,
            'pending_trigger_updates'          => 0,
            'pending_trigger_ratio'            => null,
            'pending_trigger_price'            => null,
            'last_active_idempotency_key'       => null,
            'last_unresolved_order_id'          => null,
            'last_unresolved_client_order_id'   => null,
            'last_order_status'                => RatioBotDecision::EXEC_FILLED,
            'last_fill_price'                  => $executedPrice,
            'last_position_side'               => 'long',
            'last_position_qty'                => $newQty,
            'entry_price'                      => $newAvgPrice,
            'unrealized_pnl'                   => 0,
        ]);

        $decision->update([
            'execution_status'      => RatioBotDecision::EXEC_FILLED,
            'executed_price'        => $executedPrice,
            'executed_qty'          => $executedQty,
            'order_id'              => $orderId,
            'client_order_id'       => $clientOrderId,
            'position_state_after'  => $nextPositionState,
            'executed_at'           => now(),
        ]);

        Log::info('[RatioBot] Buy filled', [
            'config_id' => $config->id,
            'price' => $executedPrice,
            'tranche_fill' => $isTrancheFill,
            'position_state' => $nextPositionState,
            'weighted_avg_entry' => $newAvgPrice,
            'total_qty' => $newQty,
            'reserve_tranches_remaining' => $tranchesRemaining,
        ]);
        $this->emitEvent($config, RatioBotEvent::EVENT_ORDER_FILLED, [
            'side' => 'BUY',
            'executed_price' => $executedPrice,
            'executed_qty' => $executedQty,
            'order_id' => $orderId,
            'client_order_id' => $clientOrderId,
            'cooldown_until' => $cooldownUntil->toIso8601String(),
            'previous_tracking' => $previousTracking,
            'tranche_fill' => $isTrancheFill,
            'weighted_avg_entry' => $newAvgPrice,
            'reserve_tranches_remaining' => $tranchesRemaining,
        ]);

        return $state->refresh();
    }

    /**
     * After a successful SELL execution.
     * V1: always a full exit, transitions holding/partial_holding → flat.
     * V2 (sellTranchesEnabled): may be a staged partial exit, transitioning
     * to PARTIAL_SOLD instead — cost basis (last_buy_price) is READ here to
     * compute this stage's realized P&L, but is NEVER itself mutated by a
     * partial exit; only a full exit clears it.
     */
    public function onSellFilled(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        float $executedPrice,
        float $executedQty,
        string $orderId,
        string $clientOrderId
    ): RatioBotState {
        $state = $this->getOrCreate($config);
        $cooldownUntil = now()->addSeconds((int) $config->cooldown_seconds_after_trade);
        $previousTracking = $this->trackingSnapshot($state);

        $priorQty = (float) ($state->last_buy_quantity ?? 0);
        $costBasisPrice = (float) ($state->last_buy_price ?? 0);
        $remainingQty = round(max(0.0, $priorQty - $executedQty), 8);
        $realizedPnlThisStage = $costBasisPrice > 0 ? ($executedPrice - $costBasisPrice) * $executedQty : 0.0;
        $realizedPnlTotal = (float) ($state->realized_pnl ?? 0) + $realizedPnlThisStage;

        $stagedExit = $config->sellTranchesEnabled();
        $tranchesRemaining = 0;
        if ($stagedExit) {
            $tranchesRemaining = $state->isPartialSold()
                ? max(0, (int) ($state->sell_tranches_remaining ?? 0) - 1)
                : max(0, (int) $config->sell_tranche_count - 1);
        }
        $continuesPartialExit = $stagedExit && $remainingQty > self::SELL_DUST_EPSILON && $tranchesRemaining > 0;

        if ($continuesPartialExit) {
            $state->update([
                'position_state'                  => RatioBotState::STATE_PARTIAL_SOLD,
                'last_sell_price'                 => $executedPrice,
                'last_sell_quantity'              => $executedQty,
                // Cost basis (last_buy_price) deliberately NOT written here —
                // a partial exit never touches the weighted-average entry.
                'last_buy_quantity'                => $remainingQty,
                'sell_tranches_remaining'          => $tranchesRemaining,
                'realized_pnl'                     => $realizedPnlTotal,
                // A partial exit forfeits any unused BUY reserve, same rule
                // as a full exit — the position is now shrinking, not growing.
                'reserve_tranches_remaining'       => 0,
                'reserve_quote_remaining'          => 0,
                'last_trade_side'                 => 'SELL',
                'last_trade_time'                 => now(),
                'last_executed_order_id'          => $orderId,
                'last_executed_client_order_id'   => $clientOrderId,
                'consecutive_order_failures'      => 0,
                'cooldown_until'                  => $cooldownUntil,
                'block_reason_code'               => null,
                'block_reason_detail'             => null,
                'awaiting_since'                  => null,
                'recovery_action_required'         => false,
                // Re-arm trailing for the next stage's own sell-side tracking.
                'sell_armed'                       => false,
                'sell_peak_ratio'                  => null,
                'sell_peak_price'                  => null,
                'buy_armed'                        => false,
                'buy_trough_ratio'                 => null,
                'buy_trough_price'                 => null,
                'pending_trigger_side'             => null,
                'pending_trigger_started_at'       => null,
                'pending_trigger_updates'          => 0,
                'pending_trigger_ratio'            => null,
                'pending_trigger_price'            => null,
                'last_active_idempotency_key'       => null,
                'last_unresolved_order_id'          => null,
                'last_unresolved_client_order_id'   => null,
                'last_order_status'                => RatioBotDecision::EXEC_FILLED,
                'last_fill_price'                  => $executedPrice,
                'last_position_side'               => 'long',
                'last_position_qty'                => $remainingQty,
                'unrealized_pnl'                   => $costBasisPrice > 0 ? ($executedPrice - $costBasisPrice) * $remainingQty : 0,
            ]);

            $decision->update([
                'execution_status'      => RatioBotDecision::EXEC_FILLED,
                'executed_price'        => $executedPrice,
                'executed_qty'          => $executedQty,
                'order_id'              => $orderId,
                'client_order_id'       => $clientOrderId,
                'position_state_after'  => RatioBotState::STATE_PARTIAL_SOLD,
                'executed_at'           => now(),
                'metadata'              => array_merge((array) ($decision->metadata ?? []), [
                    'staged_sell' => [
                        'stage_realized_pnl' => $realizedPnlThisStage,
                        'remaining_qty' => $remainingQty,
                        'remaining_cost_basis' => $costBasisPrice,
                        'sell_tranches_remaining' => $tranchesRemaining,
                    ],
                ]),
            ]);

            Log::info('[RatioBot] Staged sell filled → partial_sold', [
                'config_id' => $config->id,
                'price' => $executedPrice,
                'remaining_qty' => $remainingQty,
                'sell_tranches_remaining' => $tranchesRemaining,
                'stage_realized_pnl' => $realizedPnlThisStage,
            ]);
            $this->emitEvent($config, RatioBotEvent::EVENT_ORDER_FILLED, [
                'side' => 'SELL',
                'executed_price' => $executedPrice,
                'executed_qty' => $executedQty,
                'order_id' => $orderId,
                'client_order_id' => $clientOrderId,
                'cooldown_until' => $cooldownUntil->toIso8601String(),
                'previous_tracking' => $previousTracking,
                'staged_sell' => true,
                'remaining_qty' => $remainingQty,
                'sell_tranches_remaining' => $tranchesRemaining,
            ]);
            $this->emitEvent($config, RatioBotEvent::EVENT_STAGED_SELL_FILLED, [
                'executed_price' => $executedPrice,
                'executed_qty' => $executedQty,
                'stage_realized_pnl' => $realizedPnlThisStage,
                'remaining_qty' => $remainingQty,
                'sell_tranches_remaining' => $tranchesRemaining,
            ]);

            return $state->refresh();
        }

        $state->update([
            'position_state'                  => RatioBotState::STATE_FLAT,
            'last_sell_price'                 => $executedPrice,
            'last_sell_quantity'              => $executedQty,
            'last_buy_price'                   => 0,
            'last_buy_quantity'                => 0,
            'sell_tranches_remaining'          => 0,
            'realized_pnl'                     => $realizedPnlTotal,
            // Reserve tranches (Phase 2): a full exit forfeits any unused
            // reserve — it simply stays as USDT. The next FLAT→BUY cycle
            // re-splits fresh from config, it never carries over.
            'reserve_tranches_remaining'       => 0,
            'reserve_quote_remaining'          => 0,
            'last_trade_side'                 => 'SELL',
            'last_trade_time'                 => now(),
            'active_anchor_price'             => $executedPrice,
            'active_anchor_type'              => 'sell_anchor',
            'last_executed_order_id'          => $orderId,
            'last_executed_client_order_id'   => $clientOrderId,
            'consecutive_order_failures'      => 0,
            'cooldown_until'                  => $cooldownUntil,
            'block_reason_code'               => null,
            'block_reason_detail'             => null,
            'awaiting_since'                  => null,
            'recovery_action_required'         => false,
            'sell_armed'                       => false,
            'sell_peak_ratio'                  => null,
            'sell_peak_price'                  => null,
            'buy_armed'                        => false,
            'buy_trough_ratio'                 => null,
            'buy_trough_price'                 => null,
            'pending_trigger_side'             => null,
            'pending_trigger_started_at'       => null,
            'pending_trigger_updates'          => 0,
            'pending_trigger_ratio'            => null,
            'pending_trigger_price'            => null,
            'last_active_idempotency_key'       => null,
            'last_unresolved_order_id'          => null,
            'last_unresolved_client_order_id'   => null,
            'last_order_status'                => RatioBotDecision::EXEC_FILLED,
            'last_fill_price'                  => $executedPrice,
            'last_position_side'               => 'flat',
            'last_position_qty'                => 0,
            'entry_price'                      => null,
            'unrealized_pnl'                   => 0,
        ]);

        $decision->update([
            'execution_status'      => RatioBotDecision::EXEC_FILLED,
            'executed_price'        => $executedPrice,
            'executed_qty'          => $executedQty,
            'order_id'              => $orderId,
            'client_order_id'       => $clientOrderId,
            'position_state_after'  => RatioBotState::STATE_FLAT,
            'executed_at'           => now(),
        ]);

        Log::info('[RatioBot] Sell filled → flat', ['config_id' => $config->id, 'price' => $executedPrice]);
        $this->emitEvent($config, RatioBotEvent::EVENT_ORDER_FILLED, [
            'side' => 'SELL',
            'executed_price' => $executedPrice,
            'executed_qty' => $executedQty,
            'order_id' => $orderId,
            'client_order_id' => $clientOrderId,
            'cooldown_until' => $cooldownUntil->toIso8601String(),
            'previous_tracking' => $previousTracking,
        ]);

        return $state->refresh();
    }

    /**
     * Re-anchor the ratio-tracking reference during a recovery review.
     * Deliberately has NO cost-basis parameter — last_buy_price / entry_price
     * / realized_pnl can never be touched through this method, by design.
     */
    public function reanchorStrategy(
        RatioBotConfig $config,
        string $anchorType,
        float $newAnchorPrice,
        string $reason,
    ): RatioBotState {
        $state = $this->getOrCreate($config);

        $state->update([
            'active_anchor_price'   => $newAnchorPrice,
            'active_anchor_type'    => $anchorType,
            'position_state'        => $state->resumableTradingState(),
            'recovery_action_required' => false,
            'recovery_mode'          => null,
            'recovery_started_at'    => null,
            'recovery_review_at'     => null,
            'sell_armed'             => false,
            'sell_peak_ratio'        => null,
            'sell_peak_price'        => null,
            'buy_armed'              => false,
            'buy_trough_ratio'       => null,
            'buy_trough_price'       => null,
        ]);

        $this->emitEvent($config, RatioBotEvent::EVENT_STRATEGY_REANCHORED, [
            'anchor_type' => $anchorType,
            'new_anchor_price' => $newAnchorPrice,
            'reason' => $reason,
        ]);
        Log::info('[RatioBot] Strategy re-anchored', [
            'config_id' => $config->id,
            'anchor_type' => $anchorType,
            'new_anchor_price' => $newAnchorPrice,
        ]);

        return $state->refresh();
    }

    /**
     * Enter recovery monitoring: the bot keeps evaluating normal buy/sell
     * conditions (a real trigger always wins), but is also now eligible for
     * a periodic RecoveryReviewService review once recovery_review_at passes.
     */
    public function enterRecoveryMonitoring(RatioBotConfig $config, string $mode, string $reason): RatioBotState
    {
        $state = $this->getOrCreate($config);
        $timeoutMinutes = max(1, (int) ($config->recovery_timeout_minutes ?? 60));

        $state->update([
            'position_state' => $mode === 'cash_stuck'
                ? RatioBotState::STATE_CASH_RECOVERY_MONITORING
                : RatioBotState::STATE_RECOVERY_MONITORING,
            'recovery_action_required' => true,
            'recovery_mode' => $mode,
            'recovery_started_at' => now(),
            'recovery_review_at' => now()->addMinutes($timeoutMinutes),
        ]);

        $this->emitEvent($config, RatioBotEvent::EVENT_RECOVERY_MONITORING_ENTERED, [
            'mode' => $mode,
            'reason' => $reason,
            'review_at' => $state->recovery_review_at?->toIso8601String(),
        ]);
        Log::info('[RatioBot] Entered recovery monitoring', ['config_id' => $config->id, 'mode' => $mode]);

        return $state->refresh();
    }

    /**
     * Mark that an execution was submitted but result is not yet confirmed.
     */
    public function onAwaitingExecution(RatioBotConfig $config, RatioBotDecision $decision, ?string $clientOrderId = null, ?string $orderId = null): void
    {
        $state = $this->getOrCreate($config);
        $state->update([
            'position_state' => RatioBotState::STATE_AWAITING_EXECUTION,
            'awaiting_since' => now(),
            'last_active_idempotency_key' => $clientOrderId,
            'last_unresolved_client_order_id' => $clientOrderId,
            'last_unresolved_order_id' => $orderId,
            'last_order_status' => RatioBotDecision::EXEC_SUBMITTED,
        ]);
        $decision->update([
            'execution_status' => RatioBotDecision::EXEC_SUBMITTED,
            'client_order_id' => $clientOrderId,
            'order_id' => $orderId,
        ]);
    }

    /**
     * Record a failed order attempt.
     * Auto-pauses after consecutive failure threshold.
     */
    public function onOrderFailed(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        string $reason
    ): void {
        $state = $this->getOrCreate($config);
        $newFailCount = $state->consecutive_order_failures + 1;

        $decision->update([
            'execution_status' => RatioBotDecision::EXEC_FAILED,
            'reason'           => $reason,
        ]);

        $this->emitEvent($config, RatioBotEvent::EVENT_ORDER_FAILURE, ['reason' => $reason, 'streak' => $newFailCount]);

        $threshold = (int) $config->pause_on_order_failure_streak;
        if ($newFailCount >= $threshold) {
            $state->update([
                'position_state'         => RatioBotState::STATE_BLOCKED,
                'consecutive_order_failures' => $newFailCount,
                'block_reason_code'      => 'order_failure_streak',
                'block_reason_detail'    => "Failed {$newFailCount} orders in a row. Last reason: {$reason}",
                'awaiting_since'         => null,
                'recovery_action_required' => true,
                'last_active_idempotency_key' => null,
                'last_unresolved_order_id' => null,
                'last_unresolved_client_order_id' => null,
                'last_order_status'      => RatioBotDecision::EXEC_FAILED,
                'last_runtime_error'     => $reason,
            ]);
            $this->emitEvent($config, RatioBotEvent::EVENT_BLOCKED, ['streak' => $newFailCount]);
            Log::warning('[RatioBot] Blocked due to order failure streak', ['config_id' => $config->id, 'streak' => $newFailCount]);
        } else {
            $state->update([
                'position_state' => $state->resumableTradingState(),
                'consecutive_order_failures' => $newFailCount,
                'block_reason_code' => 'last_order_failed',
                'block_reason_detail' => $reason,
                'awaiting_since' => null,
                'last_active_idempotency_key' => null,
                'last_unresolved_order_id' => null,
                'last_unresolved_client_order_id' => null,
                'last_order_status' => RatioBotDecision::EXEC_FAILED,
                'last_runtime_error' => $reason,
            ]);
        }
    }

    public function errorReviewRequired(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        string $reasonCode,
        string $detail,
        array $context = []
    ): void {
        $state = $this->getOrCreate($config);

        $state->update([
            'position_state' => RatioBotState::STATE_ERROR_REVIEW,
            'block_reason_code' => $reasonCode,
            'block_reason_detail' => $detail,
            'awaiting_since' => null,
            'recovery_action_required' => true,
            'last_unresolved_order_id' => $context['order_id'] ?? $state->last_unresolved_order_id,
            'last_unresolved_client_order_id' => $context['client_order_id'] ?? $state->last_unresolved_client_order_id,
            'last_order_status' => RatioBotDecision::EXEC_FAILED,
            'last_runtime_error' => $detail,
        ]);
        $config->update(['enabled' => false]);

        $decision->update([
            'execution_status' => RatioBotDecision::EXEC_FAILED,
            'reason' => $detail,
            'metadata' => array_merge((array) ($decision->metadata ?? []), [
                'error_review_required' => true,
                'reason_code' => $reasonCode,
                'context' => $context,
            ]),
        ]);

        $this->emitEvent($config, RatioBotEvent::EVENT_ERROR_REVIEW_REQUIRED, [
            'code' => $reasonCode,
            'detail' => $detail,
            'decision_id' => $decision->id,
            'context' => $context,
        ]);
    }

    /**
     * Operator starts the bot — transitions idle → flat (ready to buy).
     * Only valid when an initial anchor will be set via manual buy.
     */
    public function start(RatioBotConfig $config, ?string $userId = null): RatioBotState
    {
        $state = $this->getOrCreate($config);

        // If already running, no-op
        if ($state->isTrading() || $state->position_state === RatioBotState::STATE_AWAITING_EXECUTION) {
            return $state;
        }

        $config->update(['enabled' => true]);

        if ($state->isIdle()) {
            $state->update([
                'position_state'      => RatioBotState::STATE_IDLE,
                'block_reason_code'   => null,
                'block_reason_detail' => null,
            ]);
        } else {
            $state->update([
                'position_state'      => $state->resumableTradingState(),
                'block_reason_code'   => null,
                'block_reason_detail' => null,
            ]);
        }

        $this->emitEvent($config, RatioBotEvent::EVENT_STARTED, [], $userId);
        Log::info('[RatioBot] Started', ['config_id' => $config->id]);

        return $state->refresh();
    }

    public function pause(RatioBotConfig $config, string $reason = 'operator', ?string $userId = null): void
    {
        $state = $this->getOrCreate($config);
        $state->update([
            'position_state'      => RatioBotState::STATE_PAUSED,
            'paused_at'           => now(),
            'block_reason_code'   => 'operator_pause',
            'block_reason_detail' => $reason,
        ]);
        $config->update(['enabled' => false]);
        $this->emitEvent($config, RatioBotEvent::EVENT_PAUSED, ['reason' => $reason], $userId);
        Log::info('[RatioBot] Paused', ['config_id' => $config->id]);
    }

    public function resume(RatioBotConfig $config, ?string $userId = null): void
    {
        $state = $this->getOrCreate($config);

        // Only resume from paused, not from hard blocked
        if ($state->position_state !== RatioBotState::STATE_PAUSED) {
            Log::warning('[RatioBot] Resume called on non-paused bot', ['config_id' => $config->id, 'state' => $state->position_state]);
            return;
        }

        $state->update([
            'position_state'      => $state->resumableTradingState(),
            'block_reason_code'   => null,
            'block_reason_detail' => null,
            'paused_at'           => null,
        ]);
        $config->update(['enabled' => true]);
        $this->emitEvent($config, RatioBotEvent::EVENT_RESUMED, [], $userId);
        Log::info('[RatioBot] Resumed', ['config_id' => $config->id]);
    }

    public function stop(RatioBotConfig $config, ?string $userId = null): void
    {
        $state = $this->getOrCreate($config);
        $state->update([
            'position_state'      => RatioBotState::STATE_IDLE,
            'block_reason_code'   => null,
            'block_reason_detail' => null,
            'paused_at'           => null,
            'cooldown_until'      => null,
            'sell_armed'          => false,
            'sell_peak_ratio'     => null,
            'sell_peak_price'     => null,
            'buy_armed'           => false,
            'buy_trough_ratio'    => null,
            'buy_trough_price'    => null,
            'pending_trigger_side' => null,
            'pending_trigger_started_at' => null,
            'pending_trigger_updates' => 0,
            'pending_trigger_ratio' => null,
            'pending_trigger_price' => null,
            'last_active_idempotency_key' => null,
            'last_unresolved_order_id' => null,
            'last_unresolved_client_order_id' => null,
        ]);
        $config->update(['enabled' => false]);
        $this->emitEvent($config, RatioBotEvent::EVENT_STOPPED, [], $userId);
        Log::info('[RatioBot] Stopped', ['config_id' => $config->id]);
    }

    public function block(RatioBotConfig $config, string $reasonCode, string $detail): void
    {
        $state = $this->getOrCreate($config);
        $state->update([
            'position_state'      => RatioBotState::STATE_BLOCKED,
            'block_reason_code'   => $reasonCode,
            'block_reason_detail' => $detail,
            'recovery_action_required' => true,
            'last_runtime_error'  => $detail,
        ]);
        $config->update(['enabled' => false]);
        $this->emitEvent($config, RatioBotEvent::EVENT_BLOCKED, ['code' => $reasonCode, 'detail' => $detail]);
        Log::warning('[RatioBot] Blocked', ['config_id' => $config->id, 'reason' => $reasonCode]);
    }

    public function enterCooldown(RatioBotConfig $config, int $seconds): void
    {
        $state = $this->getOrCreate($config);
        $state->update([
            'position_state' => RatioBotState::STATE_COOLDOWN,
            'cooldown_until' => now()->addSeconds($seconds),
        ]);
        $this->emitEvent($config, RatioBotEvent::EVENT_COOLDOWN_ENTERED, ['seconds' => $seconds]);
    }

    public function exitCooldownIfExpired(RatioBotConfig $config): bool
    {
        $state = $this->getOrCreate($config);
        if ($state->position_state !== RatioBotState::STATE_COOLDOWN) {
            return false;
        }
        if ($state->cooldown_until && Carbon::parse($state->cooldown_until)->isFuture()) {
            return false;
        }

        $state->update([
            'position_state' => $state->resumableTradingState(),
            'cooldown_until' => null,
        ]);
        $this->emitEvent($config, RatioBotEvent::EVENT_COOLDOWN_EXITED, []);
        return true;
    }

    public function markMarketSnapshot(
        RatioBotConfig $config,
        float $price,
        ?string $priceSource = null,
        mixed $priceTimestamp = null,
        array $runtimeMeta = [],
    ): void {
        $state = $this->getOrCreate($config);

        $state->update([
            'last_seen_price' => $price,
            'last_price_source' => $priceSource,
            'last_price_timestamp' => $this->normalizeTimestamp($priceTimestamp) ?? now(),
            'last_evaluated_at' => now(),
            'runtime_meta' => array_merge((array) ($state->runtime_meta ?? []), $runtimeMeta),
        ]);
    }

    public function rememberSignal(
        RatioBotConfig $config,
        string $decision,
        string $reason,
        ?string $signalHash = null,
        array $runtimeMeta = [],
    ): void {
        $state = $this->getOrCreate($config);

        $state->update([
            'last_signal' => $decision,
            'last_signal_at' => now(),
            'last_signal_reason' => $reason,
            'last_signal_hash' => $signalHash,
            'runtime_meta' => array_merge((array) ($state->runtime_meta ?? []), $runtimeMeta),
        ]);
    }

    public function syncPositionSnapshot(
        RatioBotConfig $config,
        string $positionSide,
        float $qty,
        ?float $entryPrice = null,
        ?float $unrealizedPnl = null,
    ): void {
        $state = $this->getOrCreate($config);
        $normalizedSide = strtolower($positionSide);
        $positionState = $qty > 0 ? RatioBotState::STATE_HOLDING : RatioBotState::STATE_FLAT;

        $state->update([
            'position_state' => $positionState,
            'last_position_side' => $qty > 0 ? $normalizedSide : 'flat',
            'last_position_qty' => max(0, $qty),
            'entry_price' => $qty > 0 ? $entryPrice : null,
            'unrealized_pnl' => $unrealizedPnl,
        ]);
    }

    public function recordTradeOutcome(RatioBotConfig $config, float $pnl): void
    {
        $state = $this->getOrCreate($config);
        $today = now()->toDateString();
        $tradeDay = optional($state->trade_day)->toDateString();

        $dailyTradeCount = $tradeDay === $today ? (int) ($state->daily_trade_count ?? 0) : 0;
        $dailyLossTotal = $tradeDay === $today ? (float) ($state->daily_loss_total ?? 0) : 0.0;
        $consecutiveLosses = $pnl < 0
            ? (($tradeDay === $today ? (int) ($state->consecutive_losses ?? 0) : 0) + 1)
            : 0;

        $state->update([
            'trade_day' => $today,
            'daily_trade_count' => $dailyTradeCount + 1,
            'daily_loss_total' => $pnl < 0 ? $dailyLossTotal + abs($pnl) : $dailyLossTotal,
            'consecutive_losses' => $consecutiveLosses,
        ]);
    }

    // -----------------------------------------------------------------------
    private function trackingSnapshot(RatioBotState $state): array
    {
        return [
            'position_state' => $state->position_state,
            'active_anchor_price' => $state->active_anchor_price,
            'active_anchor_type' => $state->active_anchor_type,
            'sell_armed' => (bool) ($state->sell_armed ?? false),
            'sell_peak_ratio' => $state->sell_peak_ratio,
            'sell_peak_price' => $state->sell_peak_price,
            'buy_armed' => (bool) ($state->buy_armed ?? false),
            'buy_trough_ratio' => $state->buy_trough_ratio,
            'buy_trough_price' => $state->buy_trough_price,
            'pending_trigger_side' => $state->pending_trigger_side,
            'pending_trigger_updates' => (int) ($state->pending_trigger_updates ?? 0),
            'pending_trigger_ratio' => $state->pending_trigger_ratio,
            'pending_trigger_price' => $state->pending_trigger_price,
        ];
    }

    private function emitEvent(RatioBotConfig $config, string $type, array $context = [], ?string $userId = null): void
    {
        RatioBotEvent::create([
            'ratio_bot_config_id' => $config->id,
            'event_type'          => $type,
            'context'             => $context ?: null,
            'triggered_by_user_id' => $userId,
            'occurred_at'         => now(),
        ]);
    }

    private function normalizeTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestampMs((int) $value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
}
