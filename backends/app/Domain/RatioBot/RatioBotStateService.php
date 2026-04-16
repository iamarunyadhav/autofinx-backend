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
    /**
     * Ensure the config has a state row; return it.
     */
    public function getOrCreate(RatioBotConfig $config): RatioBotState
    {
        return $config->state ?? RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state'      => RatioBotState::STATE_IDLE,
        ]);
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
            'last_trade_side'     => 'BUY',
            'last_trade_time'     => now(),
            'active_anchor_price' => $buyPrice,
            'active_anchor_type'  => 'buy_anchor',
            'last_executed_order_id' => $orderId,
            'block_reason_code'   => null,
            'block_reason_detail' => null,
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

        $state->update([
            'position_state'                  => RatioBotState::STATE_HOLDING,
            'last_buy_price'                  => $executedPrice,
            'last_buy_quantity'               => $executedQty,
            'last_trade_side'                 => 'BUY',
            'last_trade_time'                 => now(),
            'active_anchor_price'             => $executedPrice,
            'active_anchor_type'              => 'buy_anchor',
            'last_executed_order_id'          => $orderId,
            'last_executed_client_order_id'   => $clientOrderId,
            'consecutive_order_failures'      => 0,
            'cooldown_until'                  => $cooldownUntil,
        ]);

        $decision->update([
            'execution_status'      => RatioBotDecision::EXEC_FILLED,
            'executed_price'        => $executedPrice,
            'executed_qty'          => $executedQty,
            'order_id'              => $orderId,
            'client_order_id'       => $clientOrderId,
            'position_state_after'  => RatioBotState::STATE_HOLDING,
            'executed_at'           => now(),
        ]);

        Log::info('[RatioBot] Buy filled → holding', ['config_id' => $config->id, 'price' => $executedPrice]);

        return $state->refresh();
    }

    /**
     * After a successful SELL execution.
     * Transitions: holding → flat.
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

        $state->update([
            'position_state'                  => RatioBotState::STATE_FLAT,
            'last_sell_price'                 => $executedPrice,
            'last_sell_quantity'              => $executedQty,
            'last_trade_side'                 => 'SELL',
            'last_trade_time'                 => now(),
            'active_anchor_price'             => $executedPrice,
            'active_anchor_type'              => 'sell_anchor',
            'last_executed_order_id'          => $orderId,
            'last_executed_client_order_id'   => $clientOrderId,
            'consecutive_order_failures'      => 0,
            'cooldown_until'                  => $cooldownUntil,
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

        return $state->refresh();
    }

    /**
     * Mark that an execution was submitted but result is not yet confirmed.
     */
    public function onAwaitingExecution(RatioBotConfig $config, RatioBotDecision $decision): void
    {
        $state = $this->getOrCreate($config);
        $state->update(['position_state' => RatioBotState::STATE_AWAITING_EXECUTION]);
        $decision->update(['execution_status' => RatioBotDecision::EXEC_SUBMITTED]);
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
            ]);
            $this->emitEvent($config, RatioBotEvent::EVENT_BLOCKED, ['streak' => $newFailCount]);
            Log::warning('[RatioBot] Blocked due to order failure streak', ['config_id' => $config->id, 'streak' => $newFailCount]);
        } else {
            $state->update(['consecutive_order_failures' => $newFailCount]);
        }
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
                'position_state'      => $state->last_trade_side === 'BUY' ? RatioBotState::STATE_HOLDING : RatioBotState::STATE_FLAT,
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

        $resumeState = $state->last_trade_side === 'SELL'
            ? RatioBotState::STATE_FLAT
            : ($state->last_buy_price > 0 ? RatioBotState::STATE_HOLDING : RatioBotState::STATE_IDLE);

        $state->update([
            'position_state'      => $resumeState,
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

        $resumeState = $state->last_trade_side === 'SELL'
            ? RatioBotState::STATE_FLAT
            : RatioBotState::STATE_HOLDING;

        $state->update([
            'position_state' => $resumeState,
            'cooldown_until' => null,
        ]);
        $this->emitEvent($config, RatioBotEvent::EVENT_COOLDOWN_EXITED, []);
        return true;
    }

    // -----------------------------------------------------------------------
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
}
