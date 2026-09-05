<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Core ratio-bot decision engine.
 *
 * This service only decides and records an auditable decision. It never submits
 * an exchange order directly.
 */
class RatioBotDecisionService
{
    /**
     * Evaluate the current price against the active anchor and decide what to do.
     *
     * @return array{decision: string, ratio: float, anchor: float|null, reason: string, decision_record: RatioBotDecision}
     */
    public function evaluate(RatioBotConfig $config, float $currentPrice, array $context = []): array
    {
        $state = $config->state ?? $this->initState($config);
        $correlationId = Str::uuid()->toString();
        $priceSource = isset($context['price_source']) ? (string) $context['price_source'] : null;
        $priceTimestamp = $this->normalizePriceTimestamp($context['price_timestamp'] ?? null);
        $policy = $this->applyStrategyManagerOverrides($config, $state, $currentPrice, $this->resolveRuntimePolicy($config, $context));

        Log::info('[RatioBot] Evaluating', [
            'config_id' => $config->id,
            'symbol' => $config->symbol,
            'position_state' => $state->position_state,
            'current_price' => $currentPrice,
            'price_source' => $priceSource,
            'correlation_id' => $correlationId,
        ]);

        if ($state->isBlocked()) {
            if ($state->inCooldown()) {
                $secondsRemaining = max(0, now()->diffInSeconds($state->cooldown_until, false));
                return $this->record(
                    $config,
                    $state,
                    RatioBotDecision::DECISION_COOLDOWN,
                    $currentPrice,
                    null,
                    0,
                    0,
                    $correlationId,
                    "cooldown active: {$secondsRemaining} seconds remaining until {$state->cooldown_until}",
                    ['cooldown_until' => optional($state->cooldown_until)->toIso8601String(), 'seconds_remaining' => $secondsRemaining],
                    $priceSource,
                    $priceTimestamp,
                );
            }

            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_PAUSE,
                $currentPrice,
                null,
                0,
                0,
                $correlationId,
                'bot blocked: ' . $state->block_reason_code,
                [],
                $priceSource,
                $priceTimestamp,
            );
        }

        if (! $config->enabled) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                null,
                0,
                0,
                $correlationId,
                'bot disabled',
                [],
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($state->inCooldown()) {
            $secondsRemaining = max(0, now()->diffInSeconds($state->cooldown_until, false));
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_COOLDOWN,
                $currentPrice,
                $state->active_anchor_price ? (float) $state->active_anchor_price : null,
                0,
                0,
                $correlationId,
                "cooldown active: {$secondsRemaining} seconds remaining until {$state->cooldown_until}",
                ['cooldown_until' => optional($state->cooldown_until)->toIso8601String(), 'seconds_remaining' => $secondsRemaining],
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($state->position_state === RatioBotState::STATE_AWAITING_EXECUTION) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                null,
                0,
                0,
                $correlationId,
                'awaiting execution result for previous order',
                [],
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($state->isIdle()) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                null,
                0,
                0,
                $correlationId,
                'idle - set initial anchor via manual buy or manual sell',
                [],
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($this->missingRequiredExitGuard($config)) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_BLOCK,
                $currentPrice,
                $state->active_anchor_price ? (float) $state->active_anchor_price : null,
                0,
                0,
                $correlationId,
                'required stop-loss, take-profit, or time-stop config is missing',
                ['trigger' => 'missing_required_exit_guard'],
                $priceSource,
                $priceTimestamp,
            );
        }

        $spikeRejection = $this->rejectSingleTickSpike($config, $state, $currentPrice, $correlationId, $priceSource, $priceTimestamp);
        if ($spikeRejection !== null) {
            return $spikeRejection;
        }

        if (app(RatioBotStagePlanService::class)->enabled($config)) {
            $stageResult = $this->evaluateStagePlan($config, $state, $currentPrice, $correlationId, $priceSource, $priceTimestamp, $policy);
            if ($stageResult !== null) {
                return $stageResult;
            }
        }

        if ($config->recoveryEnabled()) {
            try {
                $this->maybeEnterRecoveryMonitoring($config, $state);
            } catch (Throwable $e) {
                Log::warning('[RatioBot] Recovery-entry check failed, continuing without recovery', [
                    'config_id' => $config->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($state->isHolding()) {
            return $this->evaluateHolding($config, $state, $currentPrice, $correlationId, $priceSource, $priceTimestamp, $policy);
        }

        if ($state->isPartialHolding()) {
            return $this->evaluatePartialHolding($config, $state, $currentPrice, $correlationId, $priceSource, $priceTimestamp, $policy);
        }

        if ($state->isPartialSold()) {
            return $this->evaluatePartialSold($config, $state, $currentPrice, $correlationId, $priceSource, $priceTimestamp, $policy);
        }

        if ($state->isFlat()) {
            return $this->evaluateFlat($config, $state, $currentPrice, $correlationId, $priceSource, $priceTimestamp, $policy);
        }

        if ($state->position_state === RatioBotState::STATE_RECOVERY_MONITORING) {
            return $this->evaluateRecoveryMonitoring($config, $state, $currentPrice, $correlationId, $priceSource, $priceTimestamp, $policy);
        }

        if ($state->position_state === RatioBotState::STATE_CASH_RECOVERY_MONITORING) {
            return $this->evaluateCashRecoveryMonitoring($config, $state, $currentPrice, $correlationId, $priceSource, $priceTimestamp, $policy);
        }

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            null,
            0,
            0,
            $correlationId,
            'unhandled state: ' . $state->position_state,
            [],
            $priceSource,
            $priceTimestamp,
        );
    }

    /**
     * The Priority Action Manager (spec §29-30). Not a separate module by
     * design — the priority order below IS this method's control flow, so
     * there's no second copy of the ordering that could drift out of sync
     * with what actually executes:
     *
     *   1. Emergency Capital Protection  -> stop-loss check, first, below.
     *   2. Reconcile pending/unknown order -> handled upstream, before this
     *      method is ever reached: evaluate()'s isBlocked()/
     *      STATE_AWAITING_EXECUTION guards + the ratio-bots:reconcile job.
     *   3. Existing position risk        -> StrategyHealthMonitor::update()
     *      (called first, below) + the sell branch runs before any buy
     *      branch, so an existing position is always assessed first.
     *   4. Confirmed profitable SELL     -> normal Stage sell / Recovery
     *      sell branches, evaluated before any BUY branch.
     *   5. Confirmed BUY opportunity     -> normal Stage buy / Recovery buy,
     *      only reached once no SELL fired.
     *   6. HOLD / WAIT                   -> the fallback every branch
     *      degrades to when its own conditions aren't met.
     */
    private function evaluateStagePlan(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $policy,
    ): ?array {
        $stagePlan = app(RatioBotStagePlanService::class);
        $lotService = app(RatioBotLotService::class);
        $health = app(StrategyHealthMonitor::class)->update($config, $state, $currentPrice);
        $state->refresh();
        $plan = $stagePlan->plan($config);
        $openLots = $lotService->openLots($config);
        $buyFilled = $lotService->buyStagesFilled($config);
        $sellCompleted = $lotService->sellStagesCompleted($config);
        $costRatio = $this->executionCostRatio($config);

        if ($openLots->isNotEmpty()) {
            $anchor = (float) ($state->last_buy_price ?: $openLots->avg('buy_price'));
            if ($anchor > 0) {
                $stopLoss = (float) ($policy['stop_loss_ratio'] ?? $this->ratioFromConfigPct($config->stop_loss_pct));
                $ratio = ($currentPrice / $anchor) - 1;
                if ($stopLoss > 0 && $ratio <= -$stopLoss) {
                    return $this->finalizeTradeSignal(
                        $config,
                        $state,
                        RatioBotDecision::DECISION_SELL,
                        $currentPrice,
                        $anchor,
                        $ratio,
                        -$stopLoss,
                        $correlationId,
                        "capital protection stop-loss triggered: ratio {$ratio} <= -{$stopLoss}",
                        ['trigger' => 'capital_protection_stop_loss', 'protective_exit' => true, 'strategy_health' => $health],
                        $priceSource,
                        $priceTimestamp,
                    );
                }
            }

            $normalOpenLots = $lotService->openLots($config, RatioBotLot::TYPE_NORMAL);
            $recoveryOpenLots = $lotService->openLots($config, RatioBotLot::TYPE_RECOVERY);

            if ($normalOpenLots->isNotEmpty()) {
                $sellResult = $this->evaluateNormalStageSell(
                    $config,
                    $state,
                    $currentPrice,
                    $plan,
                    $normalOpenLots,
                    $sellCompleted,
                    $costRatio,
                    $correlationId,
                    $priceSource,
                    $priceTimestamp,
                    $health,
                );
                if ($sellResult !== null) {
                    return $sellResult;
                }
            } elseif ($recoveryOpenLots->isNotEmpty() && $config->recoveryEnabled()) {
                // All NORMAL lots are already sold — the only inventory left is
                // the Stage 4 Recovery lot, which gets its own dedicated
                // eligibility/track-high/reversal watch and profit floor
                // instead of the normal per-stage rise%. "Existing exposure
                // before new exposure" (Core Dynamic Rule) is satisfied simply
                // by ordering: this branch is only reached once normalOpenLots
                // is empty. Same review-window gate as the BUY-side reserve
                // (spec §43: "do NOT immediately sell the remaining 20% after
                // the timer — observe first") — covers both a Stage 4 lot
                // bought through the recovery-BUY flow AND one seeded at
                // onboarding for a SELL-starting-entry bot that already held
                // inventory (see RatioBotStateService::anchorStartingInventory()).
                $timeoutMinutes = max(1, (int) $config->recovery_timeout_minutes);
                $reviewAt = $this->recoveryReviewWindow(
                    $config,
                    $state,
                    'sell_stuck',
                    "all normal sell lots are gone and only the recovery lot remains, exhausted for over {$timeoutMinutes} minutes",
                );

                if ($reviewAt === null || $reviewAt->isFuture()) {
                    return $this->record(
                        $config,
                        $state,
                        RatioBotDecision::DECISION_HOLD,
                        $currentPrice,
                        (float) $recoveryOpenLots->min('buy_price') ?: null,
                        0,
                        0,
                        $correlationId,
                        'recovery reserve: waiting for the recovery review window before observing a recovery sell',
                        [
                            'trigger' => 'recovery_sell_review_wait',
                            'recovery' => true,
                            'recovery_review_at' => optional($reviewAt)->toIso8601String(),
                            'strategy_health' => $health,
                        ],
                        $priceSource,
                        $priceTimestamp,
                    );
                }

                $recoverySellResult = $this->evaluateRecoveryLotSell(
                    $config,
                    $state,
                    $currentPrice,
                    $recoveryOpenLots,
                    $costRatio,
                    $correlationId,
                    $priceSource,
                    $priceTimestamp,
                    $health,
                );
                if ($recoverySellResult !== null) {
                    return $recoverySellResult;
                }
            }
        }

        if ($openLots->isEmpty() && $sellCompleted >= count($plan['sell_allocations']) && (float) ($state->last_sell_price ?? 0) > 0 && $currentPrice > (float) $state->last_sell_price) {
            $state->update(['position_state' => RatioBotState::STATE_STRATEGY_RANGE_EXHAUSTED_UP]);

            return $this->record(
                $config,
                $state->fresh(),
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                (float) $state->last_sell_price,
                0,
                0,
                $correlationId,
                'stage plan: all configured SELL lots are completed; price is still above the sell range, so the bot will not chase',
                ['strategy_health' => $health, 'trigger' => 'sell_range_exhausted'],
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($buyFilled >= count($plan['buy_allocations'])) {
            $recoveryResult = $this->evaluateStagePlanBuyRecovery(
                $config,
                $state,
                $currentPrice,
                $health,
                $openLots,
                $correlationId,
                $priceSource,
                $priceTimestamp,
            );
            if ($recoveryResult !== null) {
                return $recoveryResult;
            }

            $status = $health['health'] === StrategyHealthMonitor::STRATEGY_RANGE_EXHAUSTED_DOWN
                ? RatioBotState::STATE_STRATEGY_RANGE_EXHAUSTED_DOWN
                : $state->position_state;
            if ($status !== $state->position_state && $openLots->isNotEmpty()) {
                $state->update(['position_state' => $status]);
            }

            return $this->record(
                $config,
                $state->fresh(),
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $state->last_buy_price ? (float) $state->last_buy_price : null,
                0,
                0,
                $correlationId,
                $state->recovery_used_at !== null
                    ? 'stage plan: all configured BUY stages are consumed and the recovery reserve has already been used this cycle; no further BUY will be generated'
                    : 'stage plan: all configured BUY stages are consumed; no additional normal BUY will be generated',
                ['strategy_health' => $health, 'trigger' => 'buy_range_exhausted'],
                $priceSource,
                $priceTimestamp,
            );
        }

        $buyStage = $buyFilled + 1;
        $anchor = $this->stageBuyAnchor($config, $state, $buyFilled);
        if ($anchor <= 0) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                null,
                0,
                0,
                $correlationId,
                'stage plan: set a starting sell anchor or existing buy lot before automatic BUY can begin',
                ['strategy_health' => $health, 'trigger' => 'missing_stage_anchor'],
                $priceSource,
                $priceTimestamp,
            );
        }

        $state->update([
            'current_stage_side' => 'BUY',
            'current_stage_index' => $buyStage,
        ]);

        return $this->evaluateNormalStageBuy(
            $config,
            $state->fresh(),
            $currentPrice,
            $plan,
            $buyStage,
            $anchor,
            $stagePlan,
            $correlationId,
            $priceSource,
            $priceTimestamp,
            $health,
        );
    }

    /**
     * Normal Stage 1-3 BUY — eligibility (drop% from anchor) -> observe the
     * low -> reversal-watch -> confirm -> execute (spec §9-11: "never buy
     * simply because eligibility was hit"). Anchor is the previous stage's
     * actual fill price (or the starting sell anchor for stage 1) — see
     * stageBuyAnchor(). Shares the exact same reversal-tracking mechanics as
     * the Stage 4 Recovery buy via updateBuyReversalTracking().
     */
    private function evaluateNormalStageBuy(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        array $plan,
        int $buyStage,
        float $anchor,
        RatioBotStagePlanService $stagePlan,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $health,
    ): array {
        $dropPct = (float) ($plan['buy_drops'][$buyStage - 1] ?? 0);
        $dropRatio = $dropPct / 100;

        if ($dropRatio <= 0) {
            return $this->record(
                $config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, 0, 0, $correlationId,
                "stage {$buyStage} buy drop is not configured", ['trigger' => 'stage_buy_not_configured', 'strategy_health' => $health],
                $priceSource, $priceTimestamp,
            );
        }

        $costRatio = $this->executionCostRatio($config);
        $tracking = $this->updateBuyReversalTracking($state, $currentPrice, $anchor, $dropRatio, $costRatio);

        if (! $tracking['armed']) {
            return $this->record(
                $config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, $tracking['ratio'], $tracking['boundary'], $correlationId,
                "stage {$buyStage} buy waiting: drop {$tracking['ratio']} has not yet reached required {$tracking['boundary']}",
                ['trigger' => 'stage_buy_eligible_tracking', 'strategy_health' => $health],
                $priceSource, $priceTimestamp,
            );
        }

        $metadata = [
            'trigger' => 'stage_buy_reversal_watch',
            'buy_trough_ratio' => $tracking['trough_ratio'],
            'buy_trough_price' => $tracking['trough_price'],
            'rebound_from_trough_ratio' => $tracking['rebound'],
            'strategy_health' => $health,
        ];

        $reversalConfirm = $this->ratioFromConfigPct($config->buying_loss_ratio);
        if ($reversalConfirm <= 0) {
            return $this->record(
                $config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, $tracking['ratio'], $tracking['boundary'], $correlationId,
                "stage {$buyStage} buy reversal is being tracked but buying_loss_ratio is not configured to confirm it",
                $metadata, $priceSource, $priceTimestamp,
            );
        }

        if ($tracking['rebound'] >= $reversalConfirm && $tracking['ratio'] <= $tracking['boundary']) {
            $allocation = (float) ($plan['buy_allocations'][$buyStage - 1] ?? 0);
            $quote = $stagePlan->deployableQuote($config) * ($allocation / 100);

            return $this->finalizeTradeSignal(
                $config, $state, RatioBotDecision::DECISION_BUY, $currentPrice, $anchor, $tracking['ratio'], $tracking['boundary'], $correlationId,
                "stage {$buyStage} buy triggered: trough {$tracking['trough_ratio']}, rebound {$tracking['rebound']} >= required {$reversalConfirm}",
                array_merge($metadata, [
                    'trigger' => 'stage_buy',
                    'stage' => ['side' => 'BUY', 'index' => $buyStage, 'allocation_pct' => $allocation, 'drop_pct' => $dropPct],
                    'stage_quote_amount' => $quote,
                ]),
                $priceSource, $priceTimestamp,
            );
        }

        return $this->record(
            $config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, $tracking['ratio'], $tracking['boundary'], $correlationId,
            "stage {$buyStage} buy armed: trough {$tracking['trough_ratio']}, rebound {$tracking['rebound']} < required {$reversalConfirm}",
            $metadata, $priceSource, $priceTimestamp,
        );
    }

    /**
     * Normal Stage 1-3 SELL — eligibility (rise% from the most recent open
     * lot's fill price) -> observe the high -> reversal-watch -> confirm ->
     * execute (spec §21-22: "do not exit simply because the minimum
     * threshold was reached"). Once a reversal is confirmed,
     * RatioBotLotService::profitableFifoSellPlan() determines the actual
     * lots/quantity to sell: oldest open lot first, skipping (not stopping
     * at) any individual lot that doesn't clear its own buy price plus the
     * required profit buffer — the reversal watch only decides WHEN, the
     * lot selection logic still decides WHAT.
     */
    private function evaluateNormalStageSell(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        array $plan,
        Collection $normalOpenLots,
        int $sellCompleted,
        float $costRatio,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $health,
    ): ?array {
        $sellStage = min($sellCompleted + 1, count($plan['sell_allocations']));
        $sellRise = ((float) ($plan['sell_rises'][$sellStage - 1] ?? $config->sell_threshold_ratio)) / 100;
        $targetAllocation = (float) ($plan['sell_allocations'][$sellStage - 1] ?? 100);
        $minimumNet = max((float) ($config->min_net_profit_bps ?? 0) / 10000, $sellRise + $costRatio);

        $topLot = $normalOpenLots->sortByDesc('buy_stage_index')->first();
        $sellAnchor = (float) ($topLot?->buy_price ?? 0);
        if ($sellAnchor <= 0) {
            return null;
        }

        $tracking = $this->updateSellReversalTracking($state, $currentPrice, $sellAnchor, $sellRise);

        if (! $tracking['armed']) {
            // Not even close to eligible yet — fall through (return null)
            // rather than terminate here, exactly like the pre-reversal-watch
            // behavior did when reverseSellPlan() found nothing sellable:
            // lets the caller still evaluate buy-range-exhaustion/health
            // transitions below instead of masking them with a sell-wait HOLD.
            return null;
        }

        $metadata = [
            'trigger' => 'stage_sell_reversal_watch',
            'stage' => ['side' => 'SELL', 'index' => $sellStage],
            'sell_peak_ratio' => $tracking['peak_ratio'],
            'sell_peak_price' => $tracking['peak_price'],
            'pullback_from_peak_ratio' => $tracking['pullback'],
            'strategy_health' => $health,
        ];

        $reversalConfirm = $this->ratioFromConfigPct($config->selling_loss_ratio);
        if ($reversalConfirm <= 0) {
            return $this->record(
                $config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $sellAnchor, $tracking['ratio'], $sellRise, $correlationId,
                "stage {$sellStage} sell reversal is being tracked but selling_loss_ratio is not configured to confirm it",
                $metadata, $priceSource, $priceTimestamp,
            );
        }

        if ($tracking['pullback'] >= $reversalConfirm && $tracking['ratio'] >= $sellRise) {
            $lotService = app(RatioBotLotService::class);
            $desiredQty = $normalOpenLots->sum(fn ($lot) => (float) $lot->remaining_qty)
                * max(0.0, min(100.0, $targetAllocation)) / 100;
            $sellPlan = $lotService->profitableFifoSellPlan($config, $currentPrice, $minimumNet, $desiredQty, RatioBotLot::TYPE_NORMAL);

            if ($sellPlan['qty'] > 0 && $sellPlan['min_buy_price'] !== null) {
                $ratio = ($currentPrice / (float) $sellPlan['min_buy_price']) - 1;

                return $this->finalizeTradeSignal(
                    $config, $state, RatioBotDecision::DECISION_SELL, $currentPrice, (float) $sellPlan['min_buy_price'], $ratio, $sellRise, $correlationId,
                    "stage {$sellStage} fifo-lot sell triggered after confirmed reversal: peak {$tracking['peak_ratio']}, pullback {$tracking['pullback']} >= required {$reversalConfirm}",
                    array_merge($metadata, [
                        'trigger' => 'stage_fifo_lot_sell',
                        'stage' => ['side' => 'SELL', 'index' => $sellStage, 'allocation_pct' => $targetAllocation, 'rise_pct' => $sellRise * 100],
                        'sell_quantity' => $sellPlan['qty'],
                        'sell_lot_ids' => $sellPlan['lot_ids'],
                        'lot_accounting' => 'fifo_profit_guard',
                        'skipped_unprofitable_qty' => $sellPlan['skipped_unprofitable_qty'] ?? 0.0,
                        'cost_ratio' => $costRatio,
                        'minimum_net_profit_ratio' => $minimumNet,
                    ]),
                    $priceSource, $priceTimestamp,
                );
            }

            // Reversal confirmed off the top lot (pullback and ratio both
            // cleared), but profitableFifoSellPlan()'s own fresh-price profit
            // re-check didn't clear any lot (e.g. fees/slippage moved between
            // ticks) — HOLD with the actual cause. Falling through to the
            // generic "pullback < required" message below would be wrong
            // here: the pullback DID clear, that's not why this held.
            return $this->record(
                $config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $sellAnchor, $tracking['ratio'], $sellRise, $correlationId,
                "stage {$sellStage} sell reversal confirmed (peak {$tracking['peak_ratio']}, pullback {$tracking['pullback']}) but no lot currently clears the net-profit floor at the fresh price",
                $metadata, $priceSource, $priceTimestamp,
            );
        }

        return $this->record(
            $config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $sellAnchor, $tracking['ratio'], $sellRise, $correlationId,
            "stage {$sellStage} sell armed: peak {$tracking['peak_ratio']}, pullback {$tracking['pullback']} < required {$reversalConfirm}",
            $metadata, $priceSource, $priceTimestamp,
        );
    }

    /**
     * Shared Stage 4 recovery review-window gate for both directions (BUY
     * reserve deployment and SELL-recovery-lot exit). Starts the timer on
     * first entry into this mode (persisted via recovery_started_at/
     * recovery_review_at so a worker restart doesn't lose it — spec §32
     * "do not use a blocking sleep"), and returns the review deadline every
     * call after. Callers compare the returned Carbon against now() to know
     * whether the window has elapsed.
     */
    private function recoveryReviewWindow(RatioBotConfig $config, RatioBotState $state, string $mode, string $reason): ?Carbon
    {
        $timeoutMinutes = max(1, (int) $config->recovery_timeout_minutes);
        $stuckSince = $state->last_trade_time;
        $reviewAt = $stuckSince ? $stuckSince->copy()->addMinutes($timeoutMinutes) : null;

        if ($state->recovery_mode !== $mode || $state->recovery_started_at === null) {
            $state->update([
                'position_state' => RatioBotState::STATE_RECOVERY_MONITORING,
                'recovery_mode' => $mode,
                'recovery_started_at' => now(),
                'recovery_review_at' => $reviewAt,
            ]);
            RatioBotEvent::create([
                'ratio_bot_config_id' => $config->id,
                'event_type' => RatioBotEvent::EVENT_RECOVERY_MONITORING_ENTERED,
                'context' => [
                    'mode' => $mode,
                    'reason' => $reason,
                    'review_at' => optional($reviewAt)->toIso8601String(),
                ],
                'occurred_at' => now(),
            ]);
        }

        return $reviewAt;
    }

    /**
     * Shared eligibility->observe->reversal-watch tracking for a BUY
     * anchored on a drop from a reference price (spec §9-11: "never buy
     * simply because eligibility was hit" — track the low, wait for a
     * confirmed rebound). Used identically by normal Stage 1-3 buys and the
     * Stage 4 Recovery buy. Signed ratio convention matches
     * evaluateTrailingBuy(): negative once price has fallen below anchor,
     * more negative the further it falls; boundary is the negated drop
     * threshold. Mutates buy_armed/buy_trough_* on $state as a side effect.
     *
     * @return array{ratio: float, boundary: float, armed: bool, trough_ratio: ?float, trough_price: ?float, rebound: ?float}
     */
    private function updateBuyReversalTracking(RatioBotState $state, float $currentPrice, float $anchor, float $dropRatio, float $costRatio): array
    {
        $boundary = -$dropRatio - $costRatio;
        $ratio = ($currentPrice / $anchor) - 1;

        if ($ratio > $boundary) {
            // Price fully recovered back past the eligible drop line -- not
            // just a partial rebound off the tracked trough. Re-arm from
            // scratch (mirrors updateSellReversalTracking()'s equivalent
            // reset) so a later, unrelated dip can't ride a stale, deeper
            // trough left over from this abandoned one straight into a
            // premature "confirmed reversal".
            if ((bool) ($state->buy_armed ?? false)) {
                $state->update(['buy_armed' => false, 'buy_trough_ratio' => null, 'buy_trough_price' => null]);
            }
            return ['ratio' => $ratio, 'boundary' => $boundary, 'armed' => false, 'trough_ratio' => null, 'trough_price' => null, 'rebound' => null];
        }

        $troughRatio = (bool) ($state->buy_armed ?? false) && $state->buy_trough_ratio !== null
            ? min((float) $state->buy_trough_ratio, $ratio)
            : $ratio;
        $troughPrice = $troughRatio === $ratio ? $currentPrice : (float) ($state->buy_trough_price ?? $currentPrice);
        $rebound = $ratio - $troughRatio;

        $state->update(['buy_armed' => true, 'buy_trough_ratio' => $troughRatio, 'buy_trough_price' => $troughPrice]);

        return ['ratio' => $ratio, 'boundary' => $boundary, 'armed' => true, 'trough_ratio' => $troughRatio, 'trough_price' => $troughPrice, 'rebound' => $rebound];
    }

    /**
     * Shared eligibility->observe->reversal-watch tracking for a SELL
     * anchored on a rise from a reference (lot buy) price (spec §21-22:
     * "do not exit simply because the minimum threshold was reached" — track
     * the high, wait for a confirmed pullback). Used identically by normal
     * Stage 1-3 sells and the Stage 4 Recovery sell. Positive-ratio
     * convention (price above anchor = profit direction), matching the
     * existing stage_reverse_lot_sell ratio formula. Mutates
     * sell_armed/sell_peak_* on $state as a side effect.
     *
     * @return array{ratio: float, armed: bool, peak_ratio: ?float, peak_price: ?float, pullback: ?float}
     */
    private function updateSellReversalTracking(RatioBotState $state, float $currentPrice, float $anchor, float $riseRatio): array
    {
        $ratio = ($currentPrice / $anchor) - 1;

        if ($ratio < $riseRatio) {
            if ((bool) ($state->sell_armed ?? false)) {
                $state->update(['sell_armed' => false, 'sell_peak_ratio' => null, 'sell_peak_price' => null]);
            }
            return ['ratio' => $ratio, 'armed' => false, 'peak_ratio' => null, 'peak_price' => null, 'pullback' => null];
        }

        $peakRatio = (bool) ($state->sell_armed ?? false) && $state->sell_peak_ratio !== null
            ? max((float) $state->sell_peak_ratio, $ratio)
            : $ratio;
        $peakPrice = $peakRatio === $ratio ? $currentPrice : (float) ($state->sell_peak_price ?? $currentPrice);
        $pullback = $peakRatio - $ratio;

        $state->update(['sell_armed' => true, 'sell_peak_ratio' => $peakRatio, 'sell_peak_price' => $peakPrice]);

        return ['ratio' => $ratio, 'armed' => true, 'peak_ratio' => $peakRatio, 'peak_price' => $peakPrice, 'pullback' => $pullback];
    }

    /**
     * Stage 4 Recovery Reserve — BUY side ("position stuck"). Only reached
     * once all normal Stage 1-3 buy allocations are consumed AND at least one
     * normal lot is still open (price kept falling, no profitable sell
     * appeared). Reuses the exact eligibility → observe → reversal-watch →
     * confirm → execute shape as a normal stage buy (see finalizeTradeSignal/
     * confirmTrigger), just anchored on the lowest open-lot price and sized
     * from strategy_reserve_pct instead of a stage_buy_allocations entry.
     * Returns null (no-op, caller falls through to the existing
     * buy_range_exhausted HOLD) whenever recovery isn't applicable or has
     * already fired once for this exhaustion cycle — recovery_used_at is only
     * cleared by a fresh BUY→FLAT cycle (see RatioBotStateService::onSellFilled).
     */
    private function evaluateStagePlanBuyRecovery(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        array $health,
        Collection $openLots,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
    ): ?array {
        if (! $config->recoveryEnabled() || $openLots->isEmpty() || $config->recoveryReservePct() <= 0) {
            return null;
        }

        if ($state->recovery_used_at !== null) {
            return null;
        }

        $timeoutMinutes = max(1, (int) $config->recovery_timeout_minutes);
        $reviewAt = $this->recoveryReviewWindow(
            $config,
            $state,
            'position_stuck',
            "normal buy stages exhausted for over {$timeoutMinutes} minutes with no profitable sell",
        );

        if ($reviewAt === null || $reviewAt->isFuture()) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                (float) $openLots->min('buy_price') ?: null,
                0,
                0,
                $correlationId,
                'recovery reserve: waiting for the recovery review window before observing a recovery entry',
                [
                    'trigger' => 'recovery_wait',
                    'recovery' => true,
                    'recovery_review_at' => optional($reviewAt)->toIso8601String(),
                    'strategy_health' => $health,
                ],
                $priceSource,
                $priceTimestamp,
            );
        }

        $recoveryAnchor = (float) $openLots->min('buy_price');
        if ($recoveryAnchor <= 0) {
            return null;
        }

        $dropRatio = $config->recoveryBuyDropRatio();
        $costRatio = $this->executionCostRatio($config);
        $tracking = $this->updateBuyReversalTracking($state, $currentPrice, $recoveryAnchor, $dropRatio, $costRatio);

        if (! $tracking['armed']) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $recoveryAnchor,
                $tracking['ratio'],
                $tracking['boundary'],
                $correlationId,
                "recovery eligible: tracking low, drop {$tracking['ratio']} has not yet reached required {$tracking['boundary']}",
                ['trigger' => 'recovery_eligible_tracking', 'recovery' => true, 'strategy_health' => $health],
                $priceSource,
                $priceTimestamp,
            );
        }

        $metadata = [
            'trigger' => 'recovery_reversal_watch',
            'recovery' => true,
            'buy_trough_ratio' => $tracking['trough_ratio'],
            'buy_trough_price' => $tracking['trough_price'],
            'rebound_from_trough_ratio' => $tracking['rebound'],
            'strategy_health' => $health,
        ];

        $reversalConfirm = $this->ratioFromConfigPct($config->buying_loss_ratio);
        if ($reversalConfirm <= 0) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $recoveryAnchor,
                $tracking['ratio'],
                $tracking['boundary'],
                $correlationId,
                'recovery reversal is being tracked but buying_loss_ratio is not configured to confirm it',
                $metadata,
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($tracking['rebound'] >= $reversalConfirm && $tracking['ratio'] <= $tracking['boundary']) {
            $reservePct = $config->recoveryReservePct();
            $quote = ((float) ($config->base_quote_amount ?? 0)) * ($reservePct / 100);

            return $this->finalizeTradeSignal(
                $config,
                $state,
                RatioBotDecision::DECISION_BUY,
                $currentPrice,
                $recoveryAnchor,
                $tracking['ratio'],
                $tracking['boundary'],
                $correlationId,
                "recovery buy triggered: trough {$tracking['trough_ratio']}, rebound {$tracking['rebound']} >= required {$reversalConfirm}",
                array_merge($metadata, [
                    'trigger' => 'recovery_buy',
                    'stage' => ['side' => 'BUY', 'type' => RatioBotLot::TYPE_RECOVERY, 'allocation_pct' => $reservePct],
                    'stage_quote_amount' => $quote,
                ]),
                $priceSource,
                $priceTimestamp,
            );
        }

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $recoveryAnchor,
            $tracking['ratio'],
            $tracking['boundary'],
            $correlationId,
            "recovery armed: trough {$tracking['trough_ratio']}, rebound {$tracking['rebound']} < required {$reversalConfirm}",
            $metadata,
            $priceSource,
            $priceTimestamp,
        );
    }

    /**
     * Stage 4 Recovery Reserve — SELL side. Only reached once every NORMAL
     * lot is already sold and the only remaining inventory is the Recovery
     * lot itself (see the caller in evaluateStagePlan). Dedicated
     * eligibility/track-high/reversal watch using recovery_sell_rise_pct
     * instead of the normal per-stage rise%, reusing sell_armed/sell_peak_*
     * (otherwise unused by stage-plan bots, which sell immediately on
     * eligibility with no reversal watch — see the normal sell branch above).
     */
    private function evaluateRecoveryLotSell(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        Collection $recoveryOpenLots,
        float $costRatio,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $health,
    ): ?array {
        $recoveryBuyPrice = (float) $recoveryOpenLots->min('buy_price');
        if ($recoveryBuyPrice <= 0) {
            return null;
        }

        $risePct = $config->recoverySellRiseRatio();
        $tracking = $this->updateSellReversalTracking($state, $currentPrice, $recoveryBuyPrice, $risePct);

        if (! $tracking['armed']) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $recoveryBuyPrice,
                $tracking['ratio'],
                $risePct,
                $correlationId,
                "recovery sell waiting: ratio {$tracking['ratio']} < eligible {$risePct}",
                ['trigger' => 'recovery_sell_wait', 'recovery' => true, 'strategy_health' => $health],
                $priceSource,
                $priceTimestamp,
            );
        }

        $minimumNet = max((float) ($config->min_net_profit_bps ?? 0) / 10000, $risePct + $costRatio);
        $metadata = [
            'trigger' => 'recovery_sell_watch',
            'recovery' => true,
            'sell_peak_ratio' => $tracking['peak_ratio'],
            'sell_peak_price' => $tracking['peak_price'],
            'pullback_from_peak_ratio' => $tracking['pullback'],
            'cost_ratio' => $costRatio,
            'minimum_net_profit_ratio' => $minimumNet,
            'strategy_health' => $health,
        ];

        $reversalConfirm = $this->ratioFromConfigPct($config->selling_loss_ratio);
        if ($reversalConfirm <= 0) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $recoveryBuyPrice,
                $tracking['ratio'],
                $risePct,
                $correlationId,
                'recovery sell armed but selling_loss_ratio is not configured to confirm the reversal',
                $metadata,
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($tracking['pullback'] >= $reversalConfirm && $tracking['ratio'] >= $risePct) {
            return $this->finalizeTradeSignal(
                $config,
                $state,
                RatioBotDecision::DECISION_SELL,
                $currentPrice,
                $recoveryBuyPrice,
                $tracking['ratio'],
                $risePct,
                $correlationId,
                "recovery sell triggered: peak {$tracking['peak_ratio']}, pullback {$tracking['pullback']} >= required {$reversalConfirm}",
                array_merge($metadata, [
                    'trigger' => 'recovery_sell',
                    'sell_quantity' => (float) $recoveryOpenLots->sum('remaining_qty'),
                    'sell_lot_ids' => $recoveryOpenLots->pluck('id')->map(fn ($id) => (string) $id)->all(),
                ]),
                $priceSource,
                $priceTimestamp,
            );
        }

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $recoveryBuyPrice,
            $tracking['ratio'],
            $risePct,
            $correlationId,
            "recovery sell armed: peak {$tracking['peak_ratio']}, pullback {$tracking['pullback']} < required {$reversalConfirm}",
            $metadata,
            $priceSource,
            $priceTimestamp,
        );
    }

    private function stageBuyAnchor(RatioBotConfig $config, RatioBotState $state, int $buyFilled): float
    {
        if ($buyFilled <= 0) {
            return (float) ($state->last_sell_price ?: $state->active_anchor_price ?: 0);
        }

        $lastLot = app(RatioBotLotService::class)->openLots($config)
            ->sortByDesc('buy_stage_index')
            ->first();

        return $lastLot ? (float) $lastLot->buy_price : (float) ($state->last_buy_price ?? 0);
    }

    public function block(
        RatioBotConfig $config,
        float $currentPrice,
        string $reason,
        array $metadata = [],
        ?string $priceSource = null,
        mixed $priceTimestamp = null,
    ): array {
        $state = $config->state ?? $this->initState($config);
        $correlationId = Str::uuid()->toString();

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_BLOCK,
            $currentPrice,
            $state->active_anchor_price ? (float) $state->active_anchor_price : null,
            (float) ($state->current_ratio ?? 0),
            0,
            $correlationId,
            $reason,
            $metadata,
            $priceSource,
            $this->normalizePriceTimestamp($priceTimestamp),
        );
    }

    /**
     * Compute current ratio display values without creating a decision record.
     */
    public function computeCurrentRatios(RatioBotConfig $config, float $currentPrice): array
    {
        $state = $config->state;
        if (! $state) {
            return $this->emptyRatioDisplay($currentPrice);
        }

        $costRatio = $this->executionCostRatio($config);
        $result = [
            'current_price' => $currentPrice,
            'position_state' => $state->position_state,
            'last_buy_price' => $state->last_buy_price,
            'last_sell_price' => $state->last_sell_price,
            'active_anchor_price' => $state->active_anchor_price,
            'active_anchor_type' => $state->active_anchor_type,
            'sell_ratio' => null,
            'buy_ratio' => null,
            'effective_sell_ratio' => null,
            'effective_buy_ratio' => null,
            'cost_ratio' => round($costRatio, 6),
            'sell_threshold' => $config->sell_threshold_ratio,
            'buy_threshold' => $config->buy_threshold_ratio,
            'sell_distance_pct' => null,
            'buy_distance_pct' => null,
            'position_value_quote' => null,
            'unrealized_pnl' => null,
            'trigger_mode' => $config->trigger_mode ?? 'threshold',
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
            'reserve_tranches_remaining' => (int) ($state->reserve_tranches_remaining ?? 0),
            'reserve_quote_remaining' => (float) ($state->reserve_quote_remaining ?? 0),
        ];

        if (($state->isHolding() || $state->isPartialHolding() || $state->isPartialSold()) && $state->last_buy_price > 0) {
            $ratio = ($currentPrice / (float) $state->last_buy_price) - 1;
            $effective = $ratio - $costRatio;
            $result['sell_ratio'] = round($ratio, 6);
            $result['effective_sell_ratio'] = round($effective, 6);
            $result['sell_distance_pct'] = round(((float) $config->sell_threshold_ratio + $costRatio - $ratio) * 100, 4);
            if ($state->last_buy_quantity > 0) {
                $posVal = $currentPrice * (float) $state->last_buy_quantity;
                $costBasis = (float) $state->last_buy_price * (float) $state->last_buy_quantity;
                $result['position_value_quote'] = round($posVal, 8);
                $result['unrealized_pnl'] = round($posVal - $costBasis, 8);
            }

            if ($state->isPartialHolding() && (int) ($state->reserve_tranches_remaining ?? 0) > 0) {
                $buyRatio = ((float) $state->last_buy_price / $currentPrice) - 1;
                $result['buy_ratio'] = round($buyRatio, 6);
                $result['effective_buy_ratio'] = round($buyRatio - $costRatio, 6);
                $result['buy_distance_pct'] = round(((float) $config->buy_threshold_ratio + $costRatio - $buyRatio) * 100, 4);
            }
        }

        if ($state->isFlat() && $state->last_sell_price > 0) {
            $ratio = $config->usesTrailingRatio()
                ? ($currentPrice / (float) $state->last_sell_price) - 1
                : ((float) $state->last_sell_price / $currentPrice) - 1;
            $effective = $ratio - $costRatio;
            $result['buy_ratio'] = round($ratio, 6);
            $result['effective_buy_ratio'] = round($effective, 6);
            $result['buy_distance_pct'] = $config->usesTrailingRatio()
                ? round(($ratio - $this->trailingBuyBoundary($config, $costRatio)) * 100, 4)
                : round(((float) $config->buy_threshold_ratio + $costRatio - $ratio) * 100, 4);
        }

        return $result;
    }

    private function evaluateHolding(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $policy,
    ): array {
        $anchor = (float) $state->last_buy_price;
        if ($anchor <= 0) {
            return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, 0, (float) $config->sell_threshold_ratio, $correlationId, 'invalid buy anchor (zero)', [], $priceSource, $priceTimestamp);
        }

        $sellResult = $this->checkSellConditions($config, $state, $currentPrice, $anchor, $correlationId, $priceSource, $priceTimestamp, $policy);
        if ($sellResult !== null) {
            return $sellResult;
        }

        // Only reachable for non-trailing algorithms (trailing always
        // returns non-null from checkSellConditions above).
        $ratio = ($currentPrice / $anchor) - 1;
        $threshold = (float) ($policy['sell_threshold_ratio'] ?? $config->sell_threshold_ratio);
        $costRatio = $this->executionCostRatio($config);
        $requiredRatio = $threshold + $costRatio;
        $baseMetadata = array_merge(
            $this->costMetadata($config, $costRatio, $ratio - $costRatio),
            $policy['audit'] ?? [],
            [
                'dynamic_threshold_enabled' => (bool) ($policy['dynamic_threshold_enabled'] ?? false),
            ],
        );

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $anchor,
            $ratio,
            $threshold,
            $correlationId,
            "holding: ratio {$ratio} < required sell ratio {$requiredRatio}",
            array_merge($baseMetadata, ['trigger' => 'sell_threshold', 'required_ratio' => $requiredRatio]),
            $priceSource,
            $priceTimestamp,
        );
    }

    /**
     * Reserve tranches (Phase 2): watches BOTH the sell threshold (using the
     * running weighted-average entry as anchor) AND, if no sell condition
     * fires, whether price has dropped far enough again to justify deploying
     * the next reserve tranche. The tranche BUY goes through the exact same
     * finalizeTradeSignal()/confirmTrigger() path as any other BUY, so
     * Phase 1's brain-conflict confirmation delay applies to it unchanged.
     */
    private function evaluatePartialHolding(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $policy,
    ): array {
        $anchor = (float) $state->last_buy_price; // weighted-average entry
        if ($anchor <= 0) {
            return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, 0, (float) $config->sell_threshold_ratio, $correlationId, 'invalid buy anchor (zero)', [], $priceSource, $priceTimestamp);
        }

        $sellResult = $this->checkSellConditions($config, $state, $currentPrice, $anchor, $correlationId, $priceSource, $priceTimestamp, $policy);
        if ($sellResult !== null) {
            return $sellResult;
        }

        $tranchesRemaining = (int) ($state->reserve_tranches_remaining ?? 0);
        if ($tranchesRemaining <= 0) {
            // Shouldn't normally happen — onBuyFilled() promotes to plain
            // HOLDING once tranches hit 0 — but stay safe either way.
            return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, 0, (float) $config->buy_threshold_ratio, $correlationId, 'partial holding: no reserve tranches remaining', [], $priceSource, $priceTimestamp);
        }

        $buyRatio = ($anchor / $currentPrice) - 1; // same formula evaluateFlat() uses for its buy check
        $buyThreshold = (float) ($policy['buy_threshold_ratio'] ?? $config->buy_threshold_ratio);
        $costRatio = $this->executionCostRatio($config);
        $requiredBuyRatio = $buyThreshold + $costRatio;
        $metadata = array_merge(
            $this->costMetadata($config, $costRatio, $buyRatio - $costRatio),
            $policy['audit'] ?? [],
            [
                'dynamic_threshold_enabled' => (bool) ($policy['dynamic_threshold_enabled'] ?? false),
                'reserve_tranches_remaining_before' => $tranchesRemaining,
                'reserve_quote_remaining' => (float) ($state->reserve_quote_remaining ?? 0),
            ],
        );

        if ($buyRatio >= $requiredBuyRatio) {
            return $this->finalizeTradeSignal(
                $config,
                $state,
                RatioBotDecision::DECISION_BUY,
                $currentPrice,
                $anchor,
                $buyRatio,
                $buyThreshold,
                $correlationId,
                "reserve tranche buy triggered: ratio {$buyRatio} >= required {$requiredBuyRatio} (threshold {$buyThreshold}, weighted avg entry {$anchor}), {$tranchesRemaining} tranche(s) remaining",
                array_merge($metadata, ['trigger' => 'reserve_tranche_buy', 'required_ratio' => $requiredBuyRatio]),
                $priceSource,
                $priceTimestamp,
            );
        }

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $anchor,
            $buyRatio,
            $buyThreshold,
            $correlationId,
            "partial holding: ratio {$buyRatio} < required reserve-tranche buy ratio {$requiredBuyRatio}, {$tranchesRemaining} tranche(s) remaining",
            array_merge($metadata, ['trigger' => 'reserve_tranche_buy', 'required_ratio' => $requiredBuyRatio]),
            $priceSource,
            $priceTimestamp,
        );
    }

    /**
     * Staged SELL (V2), remaining stage(s). Mirrors evaluateHolding() exactly
     * except there is no re-buy branch — a partially-sold position only ever
     * watches for its next sell stage. Anchor is ALWAYS true cost basis
     * (last_buy_price), never active_anchor_price — a partial exit's
     * economics must never be affected by any strategy-anchor re-anchoring.
     */
    private function evaluatePartialSold(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $policy,
    ): array {
        $anchor = (float) $state->last_buy_price;
        if ($anchor <= 0) {
            return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, 0, (float) $config->sell_threshold_ratio, $correlationId, 'invalid buy anchor (zero)', [], $priceSource, $priceTimestamp);
        }

        $sellResult = $this->checkSellConditions($config, $state, $currentPrice, $anchor, $correlationId, $priceSource, $priceTimestamp, $policy);
        if ($sellResult !== null) {
            return $sellResult;
        }

        $ratio = ($currentPrice / $anchor) - 1;
        $threshold = (float) ($policy['sell_threshold_ratio'] ?? $config->sell_threshold_ratio);
        $costRatio = $this->executionCostRatio($config);
        $requiredRatio = $threshold + $costRatio;
        $tranchesRemaining = (int) ($state->sell_tranches_remaining ?? 0);
        $baseMetadata = array_merge(
            $this->costMetadata($config, $costRatio, $ratio - $costRatio),
            $policy['audit'] ?? [],
            [
                'dynamic_threshold_enabled' => (bool) ($policy['dynamic_threshold_enabled'] ?? false),
                'sell_tranches_remaining' => $tranchesRemaining,
            ],
        );

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $anchor,
            $ratio,
            $threshold,
            $correlationId,
            "partial sold: ratio {$ratio} < required sell ratio {$requiredRatio}, {$tranchesRemaining} sell stage(s) remaining",
            array_merge($baseMetadata, ['trigger' => 'sell_threshold', 'required_ratio' => $requiredRatio]),
            $priceSource,
            $priceTimestamp,
        );
    }

    /**
     * Stop-loss / take-profit / time-stop / sell-threshold-or-trailing.
     * Shared by evaluateHolding() and evaluatePartialHolding() — both watch
     * the same exit conditions against the same (possibly weighted-average)
     * anchor. Returns null only when nothing fired AND the algorithm is the
     * plain (non-trailing) ratio-threshold, meaning the caller should keep
     * evaluating (e.g. check for a reserve-tranche buy).
     */
    private function checkSellConditions(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        float $anchor,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $policy,
    ): ?array {
        $ratio = ($currentPrice / $anchor) - 1;
        $threshold = (float) ($policy['sell_threshold_ratio'] ?? $config->sell_threshold_ratio);
        $costRatio = $this->executionCostRatio($config);
        $requiredRatio = $threshold + $costRatio;
        $baseMetadata = array_merge(
            $this->costMetadata($config, $costRatio, $ratio - $costRatio),
            $policy['audit'] ?? [],
            [
                'dynamic_threshold_enabled' => (bool) ($policy['dynamic_threshold_enabled'] ?? false),
            ],
        );

        $stopLoss = (float) ($policy['stop_loss_ratio'] ?? $this->ratioFromConfigPct($config->stop_loss_pct));
        if ($stopLoss > 0 && $ratio <= -$stopLoss) {
            return $this->finalizeTradeSignal(
                $config,
                $state,
                RatioBotDecision::DECISION_SELL,
                $currentPrice,
                $anchor,
                $ratio,
                -$stopLoss,
                $correlationId,
                "stop-loss triggered: ratio {$ratio} <= -" . $stopLoss,
                array_merge($baseMetadata, ['trigger' => 'stop_loss', 'protective_exit' => true]),
                $priceSource,
                $priceTimestamp,
            );
        }

        $takeProfit = (float) ($policy['take_profit_ratio'] ?? $this->ratioFromConfigPct($config->take_profit_pct));
        if ($takeProfit > 0 && $ratio >= $takeProfit) {
            return $this->finalizeTradeSignal(
                $config,
                $state,
                RatioBotDecision::DECISION_SELL,
                $currentPrice,
                $anchor,
                $ratio,
                $takeProfit,
                $correlationId,
                "take-profit triggered: ratio {$ratio} >= {$takeProfit}",
                array_merge($baseMetadata, ['trigger' => 'take_profit']),
                $priceSource,
                $priceTimestamp,
            );
        }

        $timeStopMinutes = (int) ($config->time_stop_minutes ?? 0);
        if ($timeStopMinutes > 0 && $state->last_trade_time && $state->last_trade_time->copy()->addMinutes($timeStopMinutes)->isPast()) {
            return $this->finalizeTradeSignal(
                $config,
                $state,
                RatioBotDecision::DECISION_SELL,
                $currentPrice,
                $anchor,
                $ratio,
                0,
                $correlationId,
                "time-stop triggered after {$timeStopMinutes} minutes",
                array_merge($baseMetadata, ['trigger' => 'time_stop', 'protective_exit' => true]),
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($config->usesTrailingRatio()) {
            return $this->evaluateTrailingSell(
                $config,
                $state,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $costRatio,
                $correlationId,
                $baseMetadata,
                $priceSource,
                $priceTimestamp,
                $policy,
            );
        }

        Log::debug('[RatioBot] Sell check', [
            'config_id' => $config->id,
            'ratio' => $ratio,
            'threshold' => $threshold,
            'required_ratio' => $requiredRatio,
            'anchor' => $anchor,
        ]);

        if ($ratio >= $requiredRatio) {
            return $this->finalizeTradeSignal(
                $config,
                $state,
                RatioBotDecision::DECISION_SELL,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                "sell triggered: ratio {$ratio} >= required {$requiredRatio} (threshold {$threshold}, buy anchor {$anchor})",
                array_merge($baseMetadata, ['trigger' => 'sell_threshold', 'required_ratio' => $requiredRatio]),
                $priceSource,
                $priceTimestamp,
            );
        }

        return null;
    }

    private function evaluateFlat(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $policy,
    ): array {
        $anchor = (float) $state->last_sell_price;
        if ($anchor <= 0) {
            return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, 0, (float) $config->buy_threshold_ratio, $correlationId, 'invalid sell anchor (zero)', [], $priceSource, $priceTimestamp);
        }

        $ratio = ($anchor / $currentPrice) - 1;
        $threshold = (float) ($policy['buy_threshold_ratio'] ?? $config->buy_threshold_ratio);
        $costRatio = $this->executionCostRatio($config);
        $requiredRatio = $threshold + $costRatio;
        $metadata = array_merge(
            $this->costMetadata($config, $costRatio, $ratio - $costRatio),
            $policy['audit'] ?? [],
            [
                'dynamic_threshold_enabled' => (bool) ($policy['dynamic_threshold_enabled'] ?? false),
            ],
        );

        if ($config->usesTrailingRatio()) {
            $signedRatio = ($currentPrice / $anchor) - 1;

            return $this->evaluateTrailingBuy(
                $config,
                $state,
                $currentPrice,
                $anchor,
                $signedRatio,
                $threshold,
                $costRatio,
                $correlationId,
                $metadata,
                $priceSource,
                $priceTimestamp,
                $policy,
            );
        }

        Log::debug('[RatioBot] Buy check', [
            'config_id' => $config->id,
            'ratio' => $ratio,
            'threshold' => $threshold,
            'required_ratio' => $requiredRatio,
            'anchor' => $anchor,
        ]);

        if ($ratio >= $requiredRatio) {
            return $this->finalizeTradeSignal(
                $config,
                $state,
                RatioBotDecision::DECISION_BUY,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                "buy triggered: ratio {$ratio} >= required {$requiredRatio} (threshold {$threshold}, sell anchor {$anchor})",
                array_merge($metadata, ['trigger' => 'buy_threshold', 'required_ratio' => $requiredRatio]),
                $priceSource,
                $priceTimestamp,
            );
        }

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $anchor,
            $ratio,
            $threshold,
            $correlationId,
            "flat: ratio {$ratio} < required buy ratio {$requiredRatio}",
            array_merge($metadata, ['trigger' => 'buy_threshold', 'required_ratio' => $requiredRatio]),
            $priceSource,
            $priceTimestamp,
        );
    }

    /**
     * Position stuck (long, reserve exhausted, no profitable sell for over
     * recovery_timeout_minutes). Normal sell conditions ALWAYS run first — a
     * real exit always wins over recovery bookkeeping — using true cost
     * basis (last_buy_price), never active_anchor_price. Only when nothing
     * fires AND the review timer is due does this fall into a
     * RecoveryReviewService review, which can never itself return a trade.
     */
    private function evaluateRecoveryMonitoring(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $policy,
    ): array {
        $anchor = (float) $state->last_buy_price;
        if ($anchor > 0) {
            $sellResult = $this->checkSellConditions($config, $state, $currentPrice, $anchor, $correlationId, $priceSource, $priceTimestamp, $policy);
            if ($sellResult !== null) {
                return $sellResult;
            }
        }

        if ($this->recoveryReviewDue($state)) {
            return $this->runRecoveryReview($config, $state, $currentPrice, 'position_stuck', $correlationId, $priceSource, $priceTimestamp);
        }

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $anchor > 0 ? $anchor : null,
            0,
            0,
            $correlationId,
            'recovery monitoring: waiting for next review window',
            ['recovery_mode' => $state->recovery_mode, 'recovery_review_at' => optional($state->recovery_review_at)->toIso8601String()],
            $priceSource,
            $priceTimestamp,
        );
    }

    /**
     * Cash stuck (fully sold, price kept rising past the old sell anchor).
     * Unlike the position-stuck side, the buy-side reference HERE is
     * legitimately re-anchorable (active_anchor_price) — buying at a fresh
     * observation point carries no "realize a loss" risk the way re-pricing
     * a stuck long's sell trigger would. Normal buy conditions always run
     * first; only when nothing fires AND the review timer is due does this
     * fall into a recovery review.
     */
    private function evaluateCashRecoveryMonitoring(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $policy,
    ): array {
        $anchor = (float) ($state->active_anchor_price ?? $state->last_sell_price);
        if ($anchor <= 0) {
            return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, null, 0, (float) $config->buy_threshold_ratio, $correlationId, 'invalid sell anchor (zero)', [], $priceSource, $priceTimestamp);
        }

        $ratio = ($anchor / $currentPrice) - 1;
        $threshold = (float) ($policy['buy_threshold_ratio'] ?? $config->buy_threshold_ratio);
        $costRatio = $this->executionCostRatio($config);
        $requiredRatio = $threshold + $costRatio;
        $metadata = array_merge(
            $this->costMetadata($config, $costRatio, $ratio - $costRatio),
            $policy['audit'] ?? [],
            ['dynamic_threshold_enabled' => (bool) ($policy['dynamic_threshold_enabled'] ?? false)],
        );

        if ($ratio >= $requiredRatio) {
            return $this->finalizeTradeSignal(
                $config,
                $state,
                RatioBotDecision::DECISION_BUY,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                "buy triggered during cash recovery: ratio {$ratio} >= required {$requiredRatio} (threshold {$threshold}, anchor {$anchor})",
                array_merge($metadata, ['trigger' => 'buy_threshold', 'required_ratio' => $requiredRatio]),
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($this->recoveryReviewDue($state)) {
            return $this->runRecoveryReview($config, $state, $currentPrice, 'cash_stuck', $correlationId, $priceSource, $priceTimestamp);
        }

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $anchor,
            $ratio,
            $threshold,
            $correlationId,
            "cash recovery monitoring: ratio {$ratio} < required buy ratio {$requiredRatio}",
            array_merge($metadata, ['trigger' => 'buy_threshold', 'required_ratio' => $requiredRatio, 'recovery_mode' => $state->recovery_mode]),
            $priceSource,
            $priceTimestamp,
        );
    }

    private function record(
        RatioBotConfig $config,
        RatioBotState $state,
        string $decision,
        float $price,
        ?float $anchor,
        float $ratio,
        float $threshold,
        string $correlationId,
        string $reason,
        array $metadata = [],
        ?string $priceSource = null,
        ?DateTimeInterface $priceTimestamp = null,
    ): array {
        $record = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'correlation_id' => $correlationId,
            'decision' => $decision,
            'evaluated_price' => $price,
            'price_source' => $priceSource,
            'price_timestamp' => $priceTimestamp,
            'anchor_price' => $anchor,
            'anchor_type' => $state->active_anchor_type,
            'computed_ratio' => $ratio,
            'threshold_used' => $threshold,
            'position_state_before' => $state->position_state,
            'position_state_after' => $state->position_state,
            'execution_status' => $decision === RatioBotDecision::DECISION_BUY || $decision === RatioBotDecision::DECISION_SELL
                ? RatioBotDecision::EXEC_PENDING
                : RatioBotDecision::EXEC_SKIPPED,
            'reason' => $reason,
            'metadata' => $metadata ?: null,
            'decided_at' => now(),
        ]);

        $preserveLastSeen = (bool) ($metadata['preserve_last_seen'] ?? false);
        $stateUpdates = [
            'last_evaluated_at' => now(),
        ];
        if (! $preserveLastSeen) {
            $stateUpdates['last_seen_price'] = $price;
            $stateUpdates['current_ratio'] = $ratio;
        }
        // Transient guard HOLDs (slippage/net-profit/duplicate-trade/rate-limit/
        // single-tick-spike — all tagged with a 'guard' key, see finalizeTradeSignal()
        // and rejectSingleTickSpike()) fire on reasons unrelated to the multi-tick
        // confirmation state machine. Resetting pending_trigger_* here would wipe
        // confirmation progress accumulated over prior ticks even though the
        // underlying threshold breach never actually cleared — which can stall
        // a real, price-justified trade indefinitely on a noisy feed.
        $isTransientGuardHold = isset($metadata['guard']);
        if (! in_array($decision, [RatioBotDecision::DECISION_BUY, RatioBotDecision::DECISION_SELL], true)
            && ! $isTransientGuardHold
            && ! (bool) ($metadata['confirmation_pending'] ?? false)) {
            $stateUpdates['pending_trigger_side'] = null;
            $stateUpdates['pending_trigger_started_at'] = null;
            $stateUpdates['pending_trigger_updates'] = 0;
            $stateUpdates['pending_trigger_ratio'] = null;
            $stateUpdates['pending_trigger_price'] = null;
        } elseif ((bool) ($metadata['confirmation_pending'] ?? false)) {
            $stateUpdates['pending_trigger_side'] = $metadata['pending_trigger_side'] ?? $state->pending_trigger_side;
            $stateUpdates['pending_trigger_started_at'] = $metadata['pending_trigger_started_at'] ?? $state->pending_trigger_started_at;
            $stateUpdates['pending_trigger_updates'] = $metadata['pending_trigger_updates'] ?? $state->pending_trigger_updates;
            $stateUpdates['pending_trigger_ratio'] = $metadata['pending_trigger_ratio'] ?? $state->pending_trigger_ratio;
            $stateUpdates['pending_trigger_price'] = $metadata['pending_trigger_price'] ?? $state->pending_trigger_price;
        }
        if ($priceSource !== null) {
            $stateUpdates['last_price_source'] = $priceSource;
        }
        if ($priceTimestamp !== null) {
            $stateUpdates['last_price_timestamp'] = $priceTimestamp;
        }
        $state->update($stateUpdates);

        return [
            'decision' => $decision,
            'ratio' => $ratio,
            'anchor' => $anchor,
            'threshold' => $threshold,
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'decision_record' => $record,
        ];
    }

    private function evaluateTrailingSell(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        float $anchor,
        float $ratio,
        float $threshold,
        float $costRatio,
        string $correlationId,
        array $baseMetadata,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $policy,
    ): array {
        $boundary = $threshold + $costRatio;
        $sellingLoss = (float) ($policy['pullback_ratio'] ?? $this->ratioFromConfigPct($config->selling_loss_ratio));
        $metadata = array_merge($baseMetadata, [
            'trigger' => 'trailing_sell',
            'boundary_ratio' => $boundary,
            'selling_loss_ratio' => $sellingLoss,
            'profit_lock_ratio' => $policy['locked_profit_ratio'] ?? null,
            'profit_locked' => $policy['profit_locked'] ?? null,
        ]);

        if ($sellingLoss <= 0) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                'trailing sell is enabled but selling_loss_ratio is not configured',
                $metadata,
                $priceSource,
                $priceTimestamp,
            );
        }

        if (! (bool) ($state->sell_armed ?? false) && $ratio < $boundary) {
            $state->update([
                'sell_armed' => false,
                'sell_peak_ratio' => null,
                'sell_peak_price' => null,
            ]);

            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                "trailing sell waiting: ratio {$ratio} < boundary {$boundary}",
                $metadata,
                $priceSource,
                $priceTimestamp,
            );
        }

        $peakRatio = (bool) ($state->sell_armed ?? false) && $state->sell_peak_ratio !== null
            ? max((float) $state->sell_peak_ratio, $ratio)
            : $ratio;
        $peakPrice = $peakRatio === $ratio ? $currentPrice : (float) ($state->sell_peak_price ?? $currentPrice);
        $dropFromPeak = $peakRatio - $ratio;

        $state->update([
            'sell_armed' => true,
            'sell_peak_ratio' => $peakRatio,
            'sell_peak_price' => $peakPrice,
        ]);

        $metadata = array_merge($metadata, [
            'sell_peak_ratio' => $peakRatio,
            'sell_peak_price' => $peakPrice,
            'drop_from_peak_ratio' => $dropFromPeak,
            'sell_target_ratio' => $peakRatio - $sellingLoss,
        ]);

        if ($dropFromPeak >= $sellingLoss && $ratio >= $boundary) {
            return $this->finalizeTradeSignal(
                $config,
                $state,
                RatioBotDecision::DECISION_SELL,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                "trailing sell triggered: peak {$peakRatio} - selling_loss {$sellingLoss} = target " . ($peakRatio - $sellingLoss) . ", current {$ratio} <= target and current >= boundary {$boundary}",
                $metadata,
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($dropFromPeak >= $sellingLoss && $ratio < $boundary) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                "trailing sell blocked by boundary: drop {$dropFromPeak} reached, but current ratio {$ratio} < boundary {$boundary}",
                $metadata,
                $priceSource,
                $priceTimestamp,
            );
        }

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $anchor,
            $ratio,
            $threshold,
            $correlationId,
            "trailing sell armed: peak {$peakRatio}, drop {$dropFromPeak} < selling_loss {$sellingLoss}",
            $metadata,
            $priceSource,
            $priceTimestamp,
        );
    }

    private function evaluateTrailingBuy(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        float $anchor,
        float $ratio,
        float $threshold,
        float $costRatio,
        string $correlationId,
        array $baseMetadata,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
        array $policy,
    ): array {
        $boundary = $this->trailingBuyBoundaryForThreshold($threshold, $costRatio);
        $buyingLoss = (float) ($policy['rebound_ratio'] ?? $this->ratioFromConfigPct($config->buying_loss_ratio));
        $metadata = array_merge($baseMetadata, [
            'trigger' => 'trailing_buy',
            'boundary_ratio' => $boundary,
            'buy_threshold_ratio' => $threshold,
            'buying_loss_ratio' => $buyingLoss,
        ]);

        if ($buyingLoss <= 0) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                'trailing buy is enabled but buying_loss_ratio is not configured',
                $metadata,
                $priceSource,
                $priceTimestamp,
            );
        }

        if (! (bool) ($state->buy_armed ?? false) && $ratio > $boundary) {
            $state->update([
                'buy_armed' => false,
                'buy_trough_ratio' => null,
                'buy_trough_price' => null,
            ]);

            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                "trailing buy waiting: signed ratio {$ratio} > boundary {$boundary}",
                $metadata,
                $priceSource,
                $priceTimestamp,
            );
        }

        $troughRatio = (bool) ($state->buy_armed ?? false) && $state->buy_trough_ratio !== null
            ? min((float) $state->buy_trough_ratio, $ratio)
            : $ratio;
        $troughPrice = $troughRatio === $ratio ? $currentPrice : (float) ($state->buy_trough_price ?? $currentPrice);
        $reboundFromTrough = $ratio - $troughRatio;

        $state->update([
            'buy_armed' => true,
            'buy_trough_ratio' => $troughRatio,
            'buy_trough_price' => $troughPrice,
        ]);

        $metadata = array_merge($metadata, [
            'buy_trough_ratio' => $troughRatio,
            'buy_trough_price' => $troughPrice,
            'rebound_from_trough_ratio' => $reboundFromTrough,
            'buy_target_ratio' => $troughRatio + $buyingLoss,
        ]);

        if ($reboundFromTrough >= $buyingLoss && $ratio <= $boundary) {
            return $this->finalizeTradeSignal(
                $config,
                $state,
                RatioBotDecision::DECISION_BUY,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                "trailing buy triggered: trough {$troughRatio} + buying_loss {$buyingLoss} = target " . ($troughRatio + $buyingLoss) . ", current {$ratio} >= target and current <= boundary {$boundary}",
                $metadata,
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($reboundFromTrough >= $buyingLoss && $ratio > $boundary) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                "trailing buy blocked by boundary: rebound {$reboundFromTrough} reached, but current ratio {$ratio} > boundary {$boundary}",
                $metadata,
                $priceSource,
                $priceTimestamp,
            );
        }

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $anchor,
            $ratio,
            $threshold,
            $correlationId,
            "trailing buy armed: trough {$troughRatio}, rebound {$reboundFromTrough} < buying_loss {$buyingLoss}",
            $metadata,
            $priceSource,
            $priceTimestamp,
        );
    }

    private function finalizeTradeSignal(
        RatioBotConfig $config,
        RatioBotState $state,
        string $decision,
        float $price,
        ?float $anchor,
        float $ratio,
        float $threshold,
        string $correlationId,
        string $reason,
        array $metadata,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
    ): array {
        $costRatio = (float) ($metadata['cost_ratio'] ?? $this->executionCostRatio($config));
        $effectiveRatio = (float) ($metadata['effective_ratio'] ?? ($ratio - $costRatio));
        $minimumNetRatio = max(
            0.0,
            (float) ($metadata['minimum_net_profit_ratio'] ?? ((float) ($config->min_net_profit_bps ?? 0) / 10000))
        );
        $maxAllowedSlippageBps = (float) ($config->max_allowed_slippage_bps ?? 0);
        $configuredSlippageBps = (float) ($config->max_slippage_bps ?? 0);

        $metadata = array_merge($metadata, [
            'effective_ratio' => $effectiveRatio,
            'minimum_net_profit_ratio' => $minimumNetRatio,
            'max_allowed_slippage_bps' => $maxAllowedSlippageBps ?: null,
            'configured_slippage_bps' => $configuredSlippageBps,
        ]);

        if ($maxAllowedSlippageBps > 0 && $configuredSlippageBps > $maxAllowedSlippageBps) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $price,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                "trade blocked by slippage guard: configured slippage {$configuredSlippageBps} bps > max allowed {$maxAllowedSlippageBps} bps",
                array_merge($metadata, ['guard' => 'max_allowed_slippage']),
                $priceSource,
                $priceTimestamp,
            );
        }

        $protectiveExit = (bool) ($metadata['protective_exit'] ?? false);
        if ($decision === RatioBotDecision::DECISION_SELL && ! $protectiveExit && $minimumNetRatio > 0 && $effectiveRatio < $minimumNetRatio) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $price,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                "sell blocked by net-profit guard: effective ratio {$effectiveRatio} < minimum {$minimumNetRatio}",
                array_merge($metadata, ['guard' => 'minimum_net_profit']),
                $priceSource,
                $priceTimestamp,
            );
        }

        if ($decision === RatioBotDecision::DECISION_SELL && ! $protectiveExit) {
            $lotPlan = $this->profitableFifoSellPlan($config, $state, $price, $minimumNetRatio, $metadata);
            if ($lotPlan !== null && (float) $lotPlan['qty'] <= 0) {
                return $this->record(
                    $config,
                    $state,
                    RatioBotDecision::DECISION_HOLD,
                    $price,
                    $anchor,
                    $ratio,
                    $threshold,
                    $correlationId,
                    'sell blocked by lot profit guard: no managed lot clears its own buy price plus required profit buffer',
                    array_merge($metadata, ['guard' => 'lot_profit_floor', 'sell_plan' => $lotPlan]),
                    $priceSource,
                    $priceTimestamp,
                );
            }

            if ($lotPlan !== null) {
                $metadata = array_merge($metadata, [
                    'sell_quantity' => $lotPlan['qty'],
                    'sell_lot_ids' => $lotPlan['lot_ids'],
                    'sell_plan' => $lotPlan,
                    'lot_accounting' => 'fifo_profit_guard',
                ]);
                if (($lotPlan['max_buy_price'] ?? null) !== null) {
                    $anchor = (float) $lotPlan['max_buy_price'];
                    $ratio = ($price / $anchor) - 1;
                }
            }
        }

        if ($this->hasUnresolvedTrade($config)) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $price,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                'trade blocked by duplicate guard: unresolved ratio-bot trade already exists',
                array_merge($metadata, ['guard' => 'duplicate_unresolved_trade']),
                $priceSource,
                $priceTimestamp,
            );
        }

        $rateLimit = $this->tradeRateLimitBlock($config);
        if ($rateLimit !== null) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $price,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                $rateLimit['reason'],
                array_merge($metadata, ['guard' => 'trade_rate_limit'] + $rateLimit),
                $priceSource,
                $priceTimestamp,
            );
        }

        $confirmation = $this->confirmTrigger($config, $state, $decision, $price, $ratio, $priceSource);
        if (! $confirmation['confirmed']) {
            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $price,
                $anchor,
                $ratio,
                $threshold,
                $correlationId,
                $confirmation['reason'],
                array_merge($metadata, $confirmation['metadata'], ['confirmation_pending' => true]),
                $priceSource,
                $priceTimestamp,
            );
        }

        $state->update([
            'pending_trigger_side' => null,
            'pending_trigger_started_at' => null,
            'pending_trigger_updates' => 0,
            'pending_trigger_ratio' => null,
            'pending_trigger_price' => null,
        ]);

        return $this->record(
            $config,
            $state,
            $decision,
            $price,
            $anchor,
            $ratio,
            $threshold,
            $correlationId,
            $reason . ", net ratio after costs {$effectiveRatio}, cooldown passed, confirmation passed",
            array_merge($metadata, $confirmation['metadata']),
            $priceSource,
            $priceTimestamp,
        );
    }

    private function profitableFifoSellPlan(RatioBotConfig $config, RatioBotState $state, float $price, float $minimumNetRatio, array $metadata): ?array
    {
        if ((array) ($metadata['sell_lot_ids'] ?? []) !== []) {
            return null;
        }

        $desiredQty = (float) ($metadata['sell_quantity'] ?? 0);
        if ($desiredQty <= 0) {
            $desiredQty = (float) ($state->last_buy_quantity ?? 0);
            if ((bool) ($config->allow_partial_sell ?? false) && (float) ($config->partial_sell_pct ?? 0) > 0) {
                $desiredQty *= min(1.0, max(0.0, (float) $config->partial_sell_pct));
            }
        }

        if ($desiredQty <= 0) {
            return null;
        }

        return app(RatioBotLotService::class)->profitableFifoSellPlan(
            $config->fresh('state') ?? $config,
            $price,
            $minimumNetRatio,
            $desiredQty,
            RatioBotLot::TYPE_NORMAL,
        );
    }

    private function confirmTrigger(RatioBotConfig $config, RatioBotState $state, string $side, float $price, float $ratio, ?string $priceSource): array
    {
        $brainBump = $this->brainConflictBump($config, $side);
        $requiredUpdates = max(1, (int) ($config->trigger_confirm_updates ?? 1)) + $brainBump;
        $requiredSeconds = max(0, (int) ($config->trigger_confirm_seconds ?? 0));
        $stabilitySeconds = max(0, (int) ($config->min_price_stability_seconds ?? 0));
        $maxDeviation = max(0.0, (float) ($config->max_spike_deviation_bps ?? 0)) / 10000;
        $candleRequired = (bool) ($config->use_candle_close_confirmation ?? false);
        $candleReady = ! $candleRequired || in_array((string) $priceSource, ['candle-close', 'polling-candle-close'], true);

        if ($requiredUpdates <= 1 && $requiredSeconds <= 0 && $stabilitySeconds <= 0 && $candleReady) {
            return [
                'confirmed' => true,
                'reason' => 'trigger confirmation not required',
                'metadata' => [
                    'trigger_confirm_updates_required' => $requiredUpdates,
                    'trigger_confirm_seconds_required' => $requiredSeconds,
                    'use_candle_close_confirmation' => $candleRequired,
                    'candle_close_ready' => $candleReady,
                    'brain_confirm_bump' => $brainBump,
                ],
            ];
        }

        $samePending = $state->pending_trigger_side === $side && $state->pending_trigger_started_at !== null;
        $startedAt = $samePending ? $state->pending_trigger_started_at : now();
        $updates = $samePending ? max(0, (int) $state->pending_trigger_updates) + 1 : 1;
        $firstPrice = $samePending && $state->pending_trigger_price !== null ? (float) $state->pending_trigger_price : $price;
        $ageSeconds = max(0, $startedAt->diffInSeconds(now()));

        if ($samePending && $stabilitySeconds > 0 && $maxDeviation > 0 && $ageSeconds <= $stabilitySeconds && $firstPrice > 0) {
            $deviation = abs(($price / $firstPrice) - 1);
            if ($deviation > $maxDeviation) {
                $state->update([
                    'pending_trigger_side' => $side,
                    'pending_trigger_started_at' => now(),
                    'pending_trigger_updates' => 1,
                    'pending_trigger_ratio' => $ratio,
                    'pending_trigger_price' => $price,
                ]);

                return [
                    'confirmed' => false,
                    'reason' => "spike filter rejected trigger: price deviation {$deviation} > allowed {$maxDeviation}",
                    'metadata' => [
                        'spike_rejected' => true,
                        'price_deviation_ratio' => $deviation,
                        'max_spike_deviation_ratio' => $maxDeviation,
                        'trigger_updates' => 1,
                        'trigger_age_seconds' => 0,
                    ],
                ];
            }
        }

        $state->update([
            'pending_trigger_side' => $side,
            'pending_trigger_started_at' => $startedAt,
            'pending_trigger_updates' => $updates,
            'pending_trigger_ratio' => $ratio,
            'pending_trigger_price' => $firstPrice,
        ]);

        $updatesReady = $updates >= $requiredUpdates;
        $secondsReady = $ageSeconds >= $requiredSeconds;
        $stabilityReady = $stabilitySeconds <= 0 || $ageSeconds >= $stabilitySeconds;

        return [
            'confirmed' => $updatesReady && $secondsReady && $stabilityReady && $candleReady,
            'reason' => "trigger confirmation pending: updates {$updates}/{$requiredUpdates}, age {$ageSeconds}s/{$requiredSeconds}s, stability {$ageSeconds}s/{$stabilitySeconds}s, candle_close " . ($candleReady ? 'ready' : 'required'),
            'metadata' => [
                'trigger_confirm_updates_required' => $requiredUpdates,
                'trigger_confirm_seconds_required' => $requiredSeconds,
                'min_price_stability_seconds' => $stabilitySeconds,
                'use_candle_close_confirmation' => $candleRequired,
                'candle_close_ready' => $candleReady,
                'trigger_updates' => $updates,
                'trigger_age_seconds' => $ageSeconds,
                'trigger_first_price' => $firstPrice,
                'trigger_current_price' => $price,
                'pending_trigger_side' => $side,
                'pending_trigger_started_at' => $startedAt instanceof DateTimeInterface ? $startedAt->format('Y-m-d H:i:s') : $startedAt,
                'pending_trigger_updates' => $updates,
                'pending_trigger_ratio' => $ratio,
                'pending_trigger_price' => $firstPrice,
                'brain_confirm_bump' => $brainBump,
            ],
        ];
    }

    /**
     * Confirmation-only Master Brain check: if brain_enabled and the latest
     * (fresh) brain snapshot strongly disagrees with the side about to
     * trigger, require one extra confirmation tick before firing. Never
     * blocks the trade outright — always fails open (returns 0) on any
     * error, missing/stale snapshot, or when brain_enabled is off.
     */
    private function brainConflictBump(RatioBotConfig $config, string $side): int
    {
        if (! (bool) $config->brain_enabled) {
            return 0;
        }

        try {
            $brain = app(RatioBotBrainService::class);
            $snapshot = $brain->latest($config);
            if (! $snapshot || ! $snapshot->created_at) {
                return 0;
            }

            $freshnessLimitSeconds = 2 * $brain->horizonSeconds($config);
            if ($snapshot->created_at->diffInSeconds(now()) > $freshnessLimitSeconds) {
                return 0;
            }

            $minConfidence = max(1, (int) ($config->brain_min_confidence ?? 65));

            return $snapshot->conflictsWith($side, $minConfidence) ? 1 : 0;
        } catch (Throwable $e) {
            Log::warning('[RatioBot] Brain conflict check failed, failing open', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function rejectSingleTickSpike(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
    ): ?array {
        $maxDeviation = max(0.0, (float) ($config->max_spike_deviation_bps ?? 0)) / 10000;
        $lastSeen = (float) ($state->last_seen_price ?? 0);
        if ($maxDeviation <= 0 || $lastSeen <= 0 || $currentPrice <= 0) {
            return null;
        }

        $deviation = abs(($currentPrice / $lastSeen) - 1);
        if ($deviation <= $maxDeviation) {
            return null;
        }

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $state->active_anchor_price ? (float) $state->active_anchor_price : null,
            (float) ($state->current_ratio ?? 0),
            0,
            $correlationId,
            "spike filter rejected tick: price deviation {$deviation} > max {$maxDeviation}",
            [
                'guard' => 'single_tick_spike',
                'price_deviation_ratio' => $deviation,
                'max_spike_deviation_ratio' => $maxDeviation,
                'last_seen_price' => $lastSeen,
                'preserve_last_seen' => true,
            ],
            $priceSource,
            $priceTimestamp,
        );
    }

    private function hasUnresolvedTrade(RatioBotConfig $config): bool
    {
        $state = $config->state;
        if ($state && (
            $state->position_state === RatioBotState::STATE_AWAITING_EXECUTION
            || $state->last_unresolved_order_id
            || $state->last_unresolved_client_order_id
        )) {
            return true;
        }

        return RatioBotDecision::query()
            ->where('ratio_bot_config_id', $config->id)
            ->whereIn('decision', [RatioBotDecision::DECISION_BUY, RatioBotDecision::DECISION_SELL])
            ->whereIn('execution_status', [RatioBotDecision::EXEC_PENDING, RatioBotDecision::EXEC_SUBMITTED])
            ->where('created_at', '>=', now()->subMinutes(30))
            ->exists();
    }

    private function tradeRateLimitBlock(RatioBotConfig $config): ?array
    {
        $maxPerHour = (int) ($config->max_trades_per_hour ?? 0);
        if ($maxPerHour > 0) {
            $count = RatioBotDecision::query()
                ->where('ratio_bot_config_id', $config->id)
                ->where('execution_status', RatioBotDecision::EXEC_FILLED)
                ->where('executed_at', '>=', now()->subHour())
                ->count();
            if ($count >= $maxPerHour) {
                return [
                    'reason' => "trade blocked by hourly limit: {$count}/{$maxPerHour} trades already filled",
                    'window' => 'hour',
                    'count' => $count,
                    'limit' => $maxPerHour,
                ];
            }
        }

        $maxPerDay = (int) ($config->max_trades_per_day ?? 0);
        if ($maxPerDay > 0) {
            $count = RatioBotDecision::query()
                ->where('ratio_bot_config_id', $config->id)
                ->where('execution_status', RatioBotDecision::EXEC_FILLED)
                ->where('executed_at', '>=', now()->subDay())
                ->count();
            if ($count >= $maxPerDay) {
                return [
                    'reason' => "trade blocked by daily limit: {$count}/{$maxPerDay} trades already filled",
                    'window' => 'day',
                    'count' => $count,
                    'limit' => $maxPerDay,
                ];
            }
        }

        return null;
    }

    private function initState(RatioBotConfig $config): RatioBotState
    {
        return RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_IDLE,
        ]);
    }

    /**
     * V2 recovery entry. Only fires once config->recoveryEnabled() (checked
     * by the caller) — position_stuck requires an open position AND the buy
     * reserve fully exhausted (otherwise a further reserve tranche is still
     * the correct next move, not recovery); cash_stuck requires flat.
     */
    private function maybeEnterRecoveryMonitoring(RatioBotConfig $config, RatioBotState $state): void
    {
        if ($state->isRecoveryMonitoring()) {
            return;
        }

        $timeoutMinutes = max(1, (int) $config->recovery_timeout_minutes);
        $stuckSince = $state->last_trade_time;
        if (! $stuckSince || ! $stuckSince->copy()->addMinutes($timeoutMinutes)->isPast()) {
            return;
        }

        $hasOpenPosition = in_array($state->position_state, [
            RatioBotState::STATE_HOLDING,
            RatioBotState::STATE_PARTIAL_HOLDING,
            RatioBotState::STATE_PARTIAL_SOLD,
        ], true);

        if ($hasOpenPosition && (int) ($state->reserve_tranches_remaining ?? 0) <= 0) {
            app(RatioBotStateService::class)->enterRecoveryMonitoring(
                $config,
                'position_stuck',
                "no sell trigger and reserve exhausted for over {$timeoutMinutes} minutes"
            );
            $state->refresh();
            return;
        }

        if ($state->isFlat()) {
            app(RatioBotStateService::class)->enterRecoveryMonitoring(
                $config,
                'cash_stuck',
                "no buy trigger for over {$timeoutMinutes} minutes"
            );
            $state->refresh();
        }
    }

    private function recoveryReviewDue(RatioBotState $state): bool
    {
        return $state->recovery_review_at !== null && $state->recovery_review_at->isPast();
    }

    /**
     * Runs RecoveryReviewService and applies its outcome. Structurally
     * cannot execute a trade — REANCHOR_STRATEGY only ever moves
     * active_anchor_price/active_anchor_type (never last_buy_price/
     * entry_price), and PAUSE only ever blocks (never sells at a loss).
     */
    private function runRecoveryReview(
        RatioBotConfig $config,
        RatioBotState $state,
        float $currentPrice,
        string $recoveryMode,
        string $correlationId,
        ?string $priceSource,
        ?DateTimeInterface $priceTimestamp,
    ): array {
        $timeoutMinutes = max(1, (int) $config->recovery_timeout_minutes);

        try {
            $review = app(RecoveryReviewService::class)->review($config, $state, $currentPrice, $recoveryMode);
        } catch (Throwable $e) {
            Log::warning('[RatioBot] Recovery review failed, extending review window', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
            $state->update(['recovery_review_at' => now()->addMinutes($timeoutMinutes)]);

            return $this->record(
                $config,
                $state,
                RatioBotDecision::DECISION_HOLD,
                $currentPrice,
                $state->active_anchor_price ? (float) $state->active_anchor_price : null,
                0,
                0,
                $correlationId,
                'recovery review failed, will retry next window',
                ['recovery_mode' => $recoveryMode],
                $priceSource,
                $priceTimestamp,
            );
        }

        $outcome = (string) $review['outcome'];

        if ($outcome === RecoveryReviewService::OUTCOME_PAUSE) {
            app(RatioBotStateService::class)->block($config, 'recovery_review_pause', $review['reason']);
        } elseif ($outcome === RecoveryReviewService::OUTCOME_REANCHOR_STRATEGY) {
            $candidatePrice = (float) ($review['suggested_anchor']['candidate_price'] ?? 0);
            if ($candidatePrice > 0) {
                $anchorType = $recoveryMode === 'cash_stuck' ? 'sell_anchor' : 'buy_anchor';
                app(RatioBotStateService::class)->reanchorStrategy($config, $anchorType, $candidatePrice, $review['reason']);
            } else {
                $state->update(['recovery_review_at' => now()->addMinutes($timeoutMinutes)]);
            }
        } else {
            // KEEP_EXISTING_ANCHOR or CONTINUE_WAIT — stay in recovery, push the review window out.
            $state->update(['recovery_review_at' => now()->addMinutes($timeoutMinutes)]);
        }

        RatioBotEvent::create([
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_RECOVERY_REVIEW_COMPLETED,
            'context' => $review,
            'occurred_at' => now(),
        ]);

        $state->refresh();

        return $this->record(
            $config,
            $state,
            RatioBotDecision::DECISION_HOLD,
            $currentPrice,
            $state->active_anchor_price ? (float) $state->active_anchor_price : null,
            0,
            0,
            $correlationId,
            "recovery review completed: {$outcome} — {$review['reason']}",
            ['recovery_mode' => $recoveryMode, 'recovery_review' => $review],
            $priceSource,
            $priceTimestamp,
        );
    }

    /**
     * Fail-open V2 hook: composes Strategy Manager regime + dynamic-threshold
     * overrides into the runtime policy. Returns $policy completely
     * unchanged (byte-identical) whenever v2_enabled is off, the analysis
     * is inert, or anything throws — this is the sole gate that keeps every
     * pre-existing (V1) bot's evaluation behavior untouched.
     */
    private function applyStrategyManagerOverrides(RatioBotConfig $config, RatioBotState $state, float $currentPrice, array $policy): array
    {
        if (! $config->v2_enabled) {
            return $policy;
        }

        try {
            $analysis = app(StrategyManagerService::class)->analyze($config, $state, $currentPrice);
        } catch (Throwable $e) {
            Log::warning('[RatioBot] Strategy manager analysis failed, using existing policy unchanged', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            return $policy;
        }

        if (! (bool) ($analysis['enabled'] ?? false)) {
            return $policy;
        }

        $dynamic = (array) ($analysis['dynamic_thresholds'] ?? []);
        if ($dynamic === []) {
            return $policy;
        }

        // Regime-aware sell/buy thresholds are the new V2 capability and are
        // always safe to apply: the regime factor is a no-op (1.0x) whenever
        // the regime resolves to UNCERTAIN (e.g. insufficient candle
        // history), so a v2_enabled bot with no real regime signal yet sees
        // byte-identical sell/buy thresholds.
        $policy['dynamic_threshold_enabled'] = (bool) ($dynamic['enabled'] ?? $policy['dynamic_threshold_enabled']);
        $policy['sell_threshold_ratio'] = $this->normalizeRuntimeRatio($dynamic['sell_threshold_ratio'] ?? null, $policy['sell_threshold_ratio']);
        $policy['buy_threshold_ratio'] = $this->normalizeRuntimeBuyRatio($dynamic['buy_threshold_ratio'] ?? null, $policy['buy_threshold_ratio']);

        // Stop-loss/take-profit/pullback/rebound, unlike sell/buy threshold,
        // are NOT purely regime-gated inside DynamicThresholdService — it
        // derives them from trailing/ATR math that runs even when
        // `enabled` is false. Only apply them here when the bot has ALSO
        // explicitly opted into use_dynamic_thresholds — otherwise a bot
        // that only wanted staged-sell/reserve/recovery would see its
        // stop-loss silently widen for no reason it asked for.
        if ((bool) ($dynamic['enabled'] ?? false)) {
            $takeProfitFallback = $this->ratioFromConfigPct($config->take_profit_pct);
            $useDynamicTakeProfitGuard = (bool) data_get($config->strategy_params ?? [], 'use_dynamic_take_profit_guard', false);
            if ($useDynamicTakeProfitGuard || (bool) $config->require_take_profit) {
                $policy['take_profit_ratio'] = $this->normalizeRuntimeRatio($dynamic['trailing_take_profit_ratio'] ?? null, $policy['take_profit_ratio'] ?: $takeProfitFallback);
            }
            $policy['stop_loss_ratio'] = $this->normalizeRuntimeRatio($dynamic['stop_loss_ratio'] ?? null, $policy['stop_loss_ratio']);
            $policy['pullback_ratio'] = $this->normalizeRuntimeRatio($dynamic['pullback_ratio'] ?? null, $policy['pullback_ratio']);
            $policy['rebound_ratio'] = $this->normalizeRuntimeRatio($dynamic['rebound_ratio'] ?? null, $policy['rebound_ratio']);
        }

        $policy['audit']['strategy_manager'] = [
            'regime' => $analysis['regime'] ?? null,
            'regime_reason' => $analysis['regime_reason'] ?? null,
            'reserve' => $analysis['reserve'] ?? null,
            'dynamic_thresholds' => $dynamic,
        ];

        return $policy;
    }

    private function executionCostRatio(RatioBotConfig $config): float
    {
        $feeBps = max(0.0, (float) ($config->fee_bps ?? 0));
        $slippageBps = max(0.0, (float) ($config->max_slippage_bps ?? 0));

        return (($feeBps * 2) + $slippageBps) / 10000;
    }

    private function trailingBuyBoundary(RatioBotConfig $config, float $costRatio): float
    {
        $threshold = (float) ($config->buy_threshold_ratio ?? 0);
        $threshold = $threshold <= 0 ? $threshold : -abs($threshold);

        return $threshold - $costRatio;
    }

    private function trailingBuyBoundaryForThreshold(float $threshold, float $costRatio): float
    {
        $normalized = $threshold <= 0 ? $threshold : -abs($threshold);

        return $normalized - $costRatio;
    }

    private function costMetadata(RatioBotConfig $config, float $costRatio, float $effectiveRatio): array
    {
        return [
            'effective_ratio' => $effectiveRatio,
            'cost_ratio' => $costRatio,
            'fee_bps' => (float) ($config->fee_bps ?? 0),
            'max_slippage_bps' => (float) ($config->max_slippage_bps ?? 0),
            'min_net_profit_bps' => (float) ($config->min_net_profit_bps ?? 0),
            'use_advisory_filter' => (bool) ($config->use_advisory_filter ?? false),
            'advisory_mode' => (string) ($config->advisory_mode ?? 'off'),
        ];
    }

    private function resolveRuntimePolicy(RatioBotConfig $config, array $context): array
    {
        $dynamic = (array) ($context['dynamic_thresholds'] ?? []);
        $trailing = (array) ($context['trailing_profit'] ?? []);
        $takeProfitFallback = $this->ratioFromConfigPct($config->take_profit_pct);
        $useDynamicTakeProfitGuard = (bool) data_get($config->strategy_params ?? [], 'use_dynamic_take_profit_guard', false);

        return [
            'dynamic_threshold_enabled' => (bool) ($dynamic['enabled'] ?? false),
            'sell_threshold_ratio' => $this->normalizeRuntimeRatio($dynamic['sell_threshold_ratio'] ?? $dynamic['sellThreshold'] ?? null, (float) ($config->sell_threshold_ratio ?? 0)),
            'buy_threshold_ratio' => $this->normalizeRuntimeBuyRatio($dynamic['buy_threshold_ratio'] ?? $dynamic['buyThreshold'] ?? null, (float) ($config->buy_threshold_ratio ?? 0)),
            'take_profit_ratio' => $useDynamicTakeProfitGuard || (bool) $config->require_take_profit
                ? $this->normalizeRuntimeRatio($trailing['trailing_take_profit_ratio'] ?? $dynamic['trailing_take_profit_ratio'] ?? null, $takeProfitFallback)
                : $takeProfitFallback,
            'stop_loss_ratio' => $this->normalizeRuntimeRatio($trailing['stop_loss_ratio'] ?? $dynamic['stop_loss_ratio'] ?? $dynamic['stopLoss'] ?? null, $this->ratioFromConfigPct($config->stop_loss_pct)),
            'pullback_ratio' => $this->normalizeRuntimeRatio($trailing['pullback_ratio'] ?? $dynamic['pullback_ratio'] ?? null, $this->ratioFromConfigPct($config->selling_loss_ratio)),
            'rebound_ratio' => $this->normalizeRuntimeRatio($trailing['rebound_ratio'] ?? $dynamic['rebound_ratio'] ?? null, $this->ratioFromConfigPct($config->buying_loss_ratio)),
            'profit_locked' => (bool) ($trailing['profit_locked'] ?? false),
            'locked_profit_ratio' => $this->normalizeRuntimeRatio($trailing['locked_profit_ratio'] ?? null, 0.0),
            'audit' => array_filter([
                'dynamic_thresholds' => $dynamic !== [] ? $dynamic : null,
                'trailing_profit' => $trailing !== [] ? $trailing : null,
                'indicator_snapshot' => $context['indicator_snapshot'] ?? null,
                'market_health' => $context['market_health'] ?? null,
                'minimum_net_profit_ratio' => $dynamic['minimum_profit_ratio'] ?? null,
            ], static fn (mixed $value) => $value !== null),
        ];
    }

    private function normalizeRuntimeRatio(mixed $value, float $fallback): float
    {
        $ratio = is_numeric($value) ? (float) $value : $fallback;

        return $ratio > 1 ? $ratio / 100 : $ratio;
    }

    private function normalizeRuntimeBuyRatio(mixed $value, float $fallback): float
    {
        $ratio = $this->normalizeRuntimeRatio($value, $fallback);

        return $fallback < 0 && $ratio > 0 ? -$ratio : $ratio;
    }

    private function ratioFromConfigPct(mixed $value): float
    {
        $pct = (float) ($value ?? 0);
        if ($pct <= 0) {
            return 0;
        }

        return $pct > 1 ? $pct / 100 : $pct;
    }

    private function missingRequiredExitGuard(RatioBotConfig $config): bool
    {
        return ((bool) $config->require_stop_loss && $this->ratioFromConfigPct($config->stop_loss_pct) <= 0)
            || ((bool) $config->require_take_profit && $this->ratioFromConfigPct($config->take_profit_pct) <= 0)
            || ((bool) $config->require_time_stop && (int) ($config->time_stop_minutes ?? 0) <= 0);
    }

    private function normalizePriceTimestamp(mixed $value): ?Carbon
    {
        try {
            if ($value instanceof Carbon) {
                return $value;
            }
            if ($value instanceof DateTimeInterface) {
                return Carbon::instance($value);
            }
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $timestamp = (int) $value;
                return $timestamp > 9999999999
                    ? Carbon::createFromTimestampMs($timestamp)
                    : Carbon::createFromTimestamp($timestamp);
            }
            if (is_string($value) && trim($value) !== '') {
                return Carbon::parse($value);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function emptyRatioDisplay(float $currentPrice): array
    {
        return [
            'current_price' => $currentPrice,
            'position_state' => 'idle',
            'last_buy_price' => null,
            'last_sell_price' => null,
            'active_anchor_price' => null,
            'active_anchor_type' => null,
            'sell_ratio' => null,
            'buy_ratio' => null,
            'effective_sell_ratio' => null,
            'effective_buy_ratio' => null,
            'cost_ratio' => null,
            'sell_threshold' => null,
            'buy_threshold' => null,
            'sell_distance_pct' => null,
            'buy_distance_pct' => null,
            'position_value_quote' => null,
            'unrealized_pnl' => null,
            'pending_trigger_side' => null,
            'pending_trigger_updates' => 0,
            'pending_trigger_ratio' => null,
            'pending_trigger_price' => null,
        ];
    }
}
