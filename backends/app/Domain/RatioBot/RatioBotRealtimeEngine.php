<?php

namespace App\Domain\RatioBot;

use App\Domain\RatioBot\Strategies\StrategyRegistry;
use App\Domain\RatioBot\Strategies\StrategySignal;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RatioBotRealtimeEngine
{
    public function __construct(
        private readonly StrategyRegistry $strategies,
        private readonly RatioBotStateService $stateService,
        private readonly StrategyDecisionRecorder $recorder,
        private readonly RatioBotRiskService $risk,
        private readonly RatioBotExecutionService $execution,
        private readonly IndicatorService $indicators,
        private readonly DynamicThresholdService $dynamicThresholds,
        private readonly TrailingProfitService $trailingProfits,
        private readonly RatioBotBrainService $brain,
        private readonly RatioBotLiquidationGuardService $liquidationGuard,
        private readonly FuturesRecoveryReviewService $futuresRecovery,
        private readonly RatioBrain $ratioBrain,
    ) {
    }

    public function evaluate(RatioBotConfig $config, float $currentPrice, array $context = []): array
    {
        // Checked first, every tick, independent of and overriding whatever
        // the normal ladder/indicator strategy would otherwise decide --
        // Ratio Bot's "wait as long as it takes for a profitable exit"
        // philosophy is unsafe on its own under leverage, so this is the one
        // case allowed to force a loss-taking exit. Bypasses the normal risk
        // gate entirely (including kill_switch/cooldown) on purpose: an
        // operator's kill switch stops new automated entries, it should
        // never block an emergency close that prevents a worse exchange-
        // forced liquidation.
        $guard = null;
        if ($config->marketType() === 'futures' && $config->liquidationGuardEnabled()) {
            $guard = $this->liquidationGuard->assess($config);
            if ($guard['action'] === 'force_exit') {
                // Only the force-exit action is lock-protected (Part C.3) --
                // two bots sharing the same underlying exchange position
                // (see liquidation_guard's shared_position detection) could
                // otherwise both submit a closing order on the same tick and
                // race each other. Normal buys/sells on a shared symbol are
                // deliberately NOT lock-protected in this pass -- that would
                // need real cross-bot position-level accounting, a bigger
                // separate project; only this safety-critical path is.
                $lockKey = 'ratio-bot-force-exit:' . $config->workspace_id . ':' . $config->normalizedSymbol();
                $lock = Cache::lock($lockKey, 10);
                if (! $lock->get()) {
                    RatioBotEvent::create([
                        'ratio_bot_config_id' => $config->id,
                        'event_type' => RatioBotEvent::EVENT_FORCE_EXIT_LOCK_CONTENDED,
                        'context' => $guard + ['lock_key' => $lockKey],
                        'occurred_at' => now(),
                    ]);

                    $decision = $this->recorder->record($config, StrategySignal::hold(
                        'liquidation_guard_force_exit: another bot holds the shared-position force-exit lock, retrying next tick',
                        ['trigger' => 'liquidation_guard', 'liquidation_guard' => $guard],
                    ), $currentPrice, $context);

                    return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $decision->reason];
                }

                try {
                    $this->execution->cancelFuturesStopOrderSafely($config, 'liquidation_guard_force_exit');
                    $decision = $this->forceExitDecision($config, $guard, $currentPrice, $context);
                } finally {
                    $lock->release();
                }

                return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $decision->reason];
            }
        }

        // RatioBrain -- gated behind BOTH the global kill switch and this
        // bot's own opt-in (RatioBotConfig::ratioBrainEnabled()). Always
        // logs a shadow decision every tick when enabled; only the narrow
        // CONTROLLED_LOSS_EXIT recommendation ever places a real trade, and
        // only once shadow_mode has been explicitly turned off for this bot.
        if ($config->ratioBrainEnabled()) {
            $brainState = $config->state()->first();
            if ($brainState) {
                $brainResult = $this->ratioBrain->evaluate($config, $brainState, $currentPrice);
                $this->applyRatioBrainSideEffects($config, $brainState, $brainResult);

                if (! $config->ratioBrainShadowMode() && $brainResult['action'] === RatioBrain::ACTION_CONTROLLED_LOSS_EXIT) {
                    $lockKey = 'ratio-bot-brain-exit:' . $config->workspace_id . ':' . $config->normalizedSymbol();
                    $lock = Cache::lock($lockKey, 10);
                    if ($lock->get()) {
                        try {
                            $decision = $this->controlledLossExitDecision($config, $brainResult, $currentPrice, $context);
                        } finally {
                            $lock->release();
                        }

                        return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $decision->reason];
                    }
                }
            }
        }

        $indicatorSnapshot = $this->indicators->snapshot($config, $currentPrice);
        $dynamicThresholds = $this->dynamicThresholds->resolve($config, $currentPrice, $indicatorSnapshot);
        $trailingProfit = $this->trailingProfits->resolve($config, $config->state, $currentPrice, $dynamicThresholds);
        $marketHealth = $this->marketHealthSnapshot($config, $context, $indicatorSnapshot, $dynamicThresholds);
        $brainSnapshot = $config->brain_enabled ? $this->brain->resolve($config, $currentPrice) : null;

        $this->stateService->markMarketSnapshot(
            $config,
            $currentPrice,
            isset($context['price_source']) ? (string) $context['price_source'] : null,
            $context['price_timestamp'] ?? null,
            [
                'dynamic_thresholds' => $dynamicThresholds,
                'indicator_snapshot' => $indicatorSnapshot,
                'trailing_profit' => $trailingProfit,
                'market_health' => $marketHealth,
                'brain_snapshot' => $brainSnapshot ? [
                    'direction' => $brainSnapshot->direction,
                    'confidence' => $brainSnapshot->confidence,
                    'forecast_bias' => $brainSnapshot->forecast_bias,
                    'forecast_confidence' => $brainSnapshot->forecast_confidence,
                ] : null,
            ],
        );

        Log::info('[RatioBotEngine] Market analysis resolved', [
            'config_id' => $config->id,
            'symbol' => $config->normalizedSymbol(),
            'dynamic_thresholds' => $dynamicThresholds,
            'market_health' => $marketHealth,
            'indicator_summary' => [
                'market_mode' => $indicatorSnapshot['market_mode'] ?? null,
                'volatility_level' => $indicatorSnapshot['volatility_level'] ?? null,
                'rsi' => $indicatorSnapshot['rsi'] ?? null,
                'adx' => $indicatorSnapshot['adx'] ?? null,
                'volume_ratio' => $indicatorSnapshot['volume_ratio'] ?? null,
                'spread_ratio' => $indicatorSnapshot['spread_ratio'] ?? null,
            ],
        ]);

        $strategy = $this->strategies->resolve($config);
        $analysisContext = array_merge($context, [
            'dynamic_thresholds' => $dynamicThresholds,
            'indicator_snapshot' => $indicatorSnapshot,
            'trailing_profit' => $trailingProfit,
            'market_health' => $marketHealth,
        ]);
        $signal = $strategy->evaluate($config, $currentPrice, $analysisContext);
        $decision = $signal->decisionRecord ?? $this->recorder->record($config, $signal, $currentPrice, $context);

        if (! $signal->decisionRecord) {
            RatioBotEvent::create([
                'ratio_bot_config_id' => $config->id,
                'event_type' => RatioBotEvent::EVENT_SIGNAL_EMITTED,
                'context' => [
                    'decision' => $decision->decision,
                    'reason' => $decision->reason,
                    'decision_id' => $decision->id,
                ],
                'occurred_at' => now(),
            ]);
        }

        if ($decision->decision === RatioBotDecision::DECISION_BLOCK) {
            $this->stateService->block($config, 'strategy_block', (string) $decision->reason);

            return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $decision->reason];
        }

        if (! $decision->isTrade()) {
            if ($guard !== null && $config->futuresRecoveryEnabled()) {
                $this->maybeReviewFuturesRecovery($config, $currentPrice, $guard);
            }

            return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $decision->reason];
        }

        $indicatorConfirmation = $this->indicators->confirm($config, $decision->decision, $currentPrice, $indicatorSnapshot, [
            'decision_metadata' => (array) ($decision->metadata ?? []),
            'dynamic_thresholds' => $dynamicThresholds,
            'trailing_profit' => $trailingProfit,
            'market_health' => $marketHealth,
        ]);
        $riskBlock = $this->risk->preTradeBlockReason($config, $decision, [
            'dynamic_thresholds' => $dynamicThresholds,
            'indicator_snapshot' => $indicatorSnapshot,
            'indicator_confirmation' => $indicatorConfirmation,
            'trailing_profit' => $trailingProfit,
            'market_health' => $marketHealth,
        ]);
        if ($riskBlock !== null) {
            $decision->update([
                'execution_status' => RatioBotDecision::EXEC_SKIPPED,
                'reason' => $riskBlock['detail'],
                'metadata' => array_merge((array) ($decision->metadata ?? []), [
                    'risk_block' => $riskBlock,
                    'indicator_confirmation' => $indicatorConfirmation,
                    'market_health' => $marketHealth,
                ]),
            ]);

            if ((bool) $riskBlock['pause']) {
                $this->stateService->block($config, (string) $riskBlock['code'], (string) $riskBlock['detail']);
            }

            Log::warning('[RatioBotEngine] Trade blocked by risk guard', [
                'config_id' => $config->id,
                'decision_id' => $decision->id,
                'code' => $riskBlock['code'],
            ]);

            return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $riskBlock['detail']];
        }

        if ($config->isAutoMode()) {
            $this->execution->execute($config, $decision);

            return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $decision->reason];
        }

        // Same decision engine as auto mode — only the execution authorization
        // differs (spec §51: "do not maintain two independent algorithms").
        // Tagged in metadata (not just the reason string) so
        // RatioBotRuntimeQueryService::pendingApproval() can find it reliably,
        // and left query-able by RatioBotController::approveDecision() to be
        // executed later through the exact same RatioBotExecutionService path
        // auto mode uses — including its fresh-price/net-profit re-check.
        $decision->update([
            'execution_status' => RatioBotDecision::EXEC_SKIPPED,
            'reason' => 'mode=' . $config->mode . ' requires manual approval',
            'metadata' => array_merge((array) ($decision->metadata ?? []), [
                'requires_manual_approval' => true,
                'manual_decision' => null,
            ]),
        ]);

        return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $decision->reason];
    }

    /**
     * Mirrors StrategyDecisionRecorder::record()'s shape so this decision is
     * indistinguishable in storage/reporting from a normal one, then routes
     * through the exact same RatioBotExecutionService::execute() path a
     * normal EXIT_LONG/EXIT_SHORT decision already uses (reduce-only close,
     * duplicate-in-flight protection, sandbox simulation) -- nothing new to
     * trust here, only a new reason a pre-existing decision type can fire.
     */
    private function forceExitDecision(RatioBotConfig $config, array $guard, float $currentPrice, array $context): RatioBotDecision
    {
        $side = $guard['position_side'] === 'short' ? RatioBotDecision::DECISION_EXIT_SHORT : RatioBotDecision::DECISION_EXIT_LONG;

        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'correlation_id' => (string) Str::uuid(),
            'decision' => $side,
            'evaluated_price' => $currentPrice,
            'price_source' => isset($context['price_source']) ? (string) $context['price_source'] : null,
            'position_state_before' => $config->state?->position_state,
            'position_state_after' => $config->state?->position_state,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'reason' => 'liquidation_guard_force_exit: ' . $guard['reason'],
            'metadata' => [
                'trigger' => 'liquidation_guard',
                'protective_exit' => true,
                'liquidation_guard' => $guard,
            ],
            'decided_at' => now(),
        ]);

        RatioBotEvent::create([
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_LIQUIDATION_GUARD_FORCE_EXIT,
            'context' => $guard + ['decision_id' => (string) $decision->id],
            'occurred_at' => now(),
        ]);

        Log::warning('[RatioBotEngine] Liquidation guard forcing protective exit', [
            'config_id' => $config->id,
            'symbol' => $config->normalizedSymbol(),
            'buffer_pct' => $guard['buffer_pct'],
            'reason' => $guard['reason'],
        ]);

        if ($config->isAutoMode()) {
            $this->execution->execute($config, $decision);
        }

        return $decision->fresh();
    }

    /**
     * Part B -- checks a stuck futures position for a single, bounded,
     * one-time top-up. Deliberately does NOT call
     * RatioBotStateService::block()/pause() for the PAUSE outcome the way
     * spot's equivalent does: block() also sets enabled=false, which stops
     * this bot from being evaluated at all -- including Part A's own
     * force-exit check and any natural stop-loss/opposite-signal exit. That
     * would freeze exactly the safety nets a stuck position needs most.
     * PAUSE here means only "don't top up, re-check next window" -- the
     * bot keeps running normally otherwise.
     */
    private function maybeReviewFuturesRecovery(RatioBotConfig $config, float $currentPrice, array $guard): void
    {
        $state = $config->state()->first();
        if (! $state || (float) ($state->last_position_qty ?? 0) <= 0 || ! $state->futures_position_opened_at) {
            return;
        }

        $timeoutMinutes = $config->futuresRecoveryTimeoutMinutes();
        if (! $state->futures_position_opened_at->copy()->addMinutes($timeoutMinutes)->isPast()) {
            return;
        }

        $entryPrice = (float) ($state->entry_price ?? 0);
        $side = strtolower((string) ($state->last_position_side ?? ''));
        if ($entryPrice <= 0 || ! in_array($side, ['long', 'short'], true)) {
            return;
        }

        $atLoss = $side === 'short' ? $currentPrice > $entryPrice : $currentPrice < $entryPrice;
        if (! $atLoss) {
            return; // long-held winner, not a stuck/losing position -- nothing to recover
        }

        if ($state->futures_recovery_review_at !== null && $state->futures_recovery_review_at->isFuture()) {
            return;
        }

        $review = $this->futuresRecovery->review($config, $state, $currentPrice, $guard);

        if ($review['outcome'] === FuturesRecoveryReviewService::OUTCOME_TOP_UP_ONCE) {
            $this->executeFuturesRecoveryTopup($config, $currentPrice, $review);

            return;
        }

        $state->update(['futures_recovery_review_at' => now()->addMinutes($timeoutMinutes)]);

        if ($review['outcome'] === FuturesRecoveryReviewService::OUTCOME_PAUSE) {
            RatioBotEvent::create([
                'ratio_bot_config_id' => $config->id,
                'event_type' => RatioBotEvent::EVENT_FUTURES_RECOVERY_PAUSED,
                'context' => $review,
                'occurred_at' => now(),
            ]);
        }
    }

    private function executeFuturesRecoveryTopup(RatioBotConfig $config, float $currentPrice, array $review): void
    {
        $state = $config->state()->first();
        $side = strtolower((string) ($state?->last_position_side ?? ''));
        $decisionSide = $side === 'short' ? RatioBotDecision::DECISION_SHORT : RatioBotDecision::DECISION_LONG;

        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'correlation_id' => (string) Str::uuid(),
            'decision' => $decisionSide,
            'evaluated_price' => $currentPrice,
            'position_state_before' => $state?->position_state,
            'position_state_after' => $state?->position_state,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'reason' => 'futures_recovery_topup: ' . $review['reason'],
            'metadata' => [
                'trigger' => 'futures_recovery_topup',
                'futures_recovery_topup_notional' => (float) ($review['topup_notional'] ?? 0),
                'confidence' => ((float) ($review['confidence_pct'] ?? 0)) / 100,
            ],
            'decided_at' => now(),
        ]);

        // Same risk gate every other entry goes through (kill_switch,
        // cooldown, stale_feed, indicator confirmation, this bot's own
        // liquidation_guard_block_new_entries check) -- a recovery top-up is
        // a real additional entry, it does not get to skip these.
        $riskBlock = $this->risk->preTradeBlockReason($config, $decision);
        if ($riskBlock !== null) {
            $decision->update([
                'execution_status' => RatioBotDecision::EXEC_SKIPPED,
                'reason' => $riskBlock['detail'],
                'metadata' => array_merge((array) $decision->metadata, ['risk_block' => $riskBlock]),
            ]);

            return;
        }

        if ($config->isAutoMode()) {
            $this->execution->execute($config, $decision);
        }
    }

    /**
     * Side effects a pure "assess" call (RatioBrain::evaluate(), mirroring
     * RatioBotLiquidationGuardService::assess()'s own read-only shape)
     * deliberately does not perform itself. Runs regardless of shadow mode
     * for the emergency status + trend-velocity bookkeeping (neither places
     * a trade); the observation-window timer override is the one piece
     * gated to shadow_mode=false, since it's a real behavior change to the
     * existing recovery-review cadence.
     */
    private function applyRatioBrainSideEffects(RatioBotConfig $config, RatioBotState $state, array $result): void
    {
        $isEmergency = (bool) data_get($result, 'component_breakdown.emergency.is_emergency', false);
        $this->stateService->syncEmergencyStatus($config, $isEmergency);

        $observationWindow = data_get($result, 'component_breakdown.observation_window');
        $trendScore = data_get($result, 'component_breakdown.trend_score');
        if ($observationWindow === null || $trendScore === null) {
            return;
        }

        $updates = [
            'recovery_trend_composite_at_last_review' => (float) $trendScore['composite_score'],
            'recovery_trend_reviewed_at' => now(),
        ];

        if (! $config->ratioBrainShadowMode()) {
            $updates['recovery_review_at'] = now()->addMinutes((int) $observationWindow['minutes']);
        }

        $state->update($updates);
    }

    /**
     * Mirrors forceExitDecision()'s shape (see that method's docblock) so
     * this decision is indistinguishable in storage/reporting from a normal
     * one, then routes through the exact same RatioBotExecutionService::
     * execute() path a normal SELL already uses. Explicitly sets
     * metadata.sell_quantity to the FULL stuck-position quantity RatioBrain
     * measured -- without it, resolveQuantity() would fall back to whatever
     * partial-sell/staged-sell settings this bot has configured, which would
     * under-close a position this check specifically determined should be
     * fully exited.
     */
    private function controlledLossExitDecision(RatioBotConfig $config, array $brainResult, float $currentPrice, array $context): RatioBotDecision
    {
        $state = $config->state()->first();
        $lotIds = (array) data_get($brainResult, 'component_breakdown.candidate_lot_ids', []);
        $sellQuantity = $lotIds !== []
            ? (float) \App\Models\RatioBotLot::query()->whereIn('id', $lotIds)->sum('remaining_qty')
            : (float) ($state?->last_buy_quantity ?? 0);

        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'correlation_id' => (string) Str::uuid(),
            'decision' => RatioBotDecision::DECISION_SELL,
            'evaluated_price' => $currentPrice,
            'price_source' => isset($context['price_source']) ? (string) $context['price_source'] : null,
            'position_state_before' => $state?->position_state,
            'position_state_after' => $state?->position_state,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'reason' => 'ratio_brain_controlled_loss_exit: ' . $brainResult['reason'],
            'metadata' => [
                'trigger' => 'ratio_brain_controlled_loss_exit',
                'protective_exit' => true,
                'sell_quantity' => $sellQuantity,
                'ratio_brain' => $brainResult,
            ],
            'decided_at' => now(),
        ]);

        RatioBotEvent::create([
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_RATIO_BRAIN_CONTROLLED_LOSS_EXIT,
            'context' => $brainResult + ['decision_id' => (string) $decision->id],
            'occurred_at' => now(),
        ]);

        Log::warning('[RatioBotEngine] RatioBrain executing a controlled loss exit', [
            'config_id' => $config->id,
            'symbol' => $config->normalizedSymbol(),
            'sell_quantity' => $sellQuantity,
            'reason' => $brainResult['reason'],
        ]);

        if ($config->isAutoMode()) {
            $this->execution->execute($config, $decision);
        }

        return $decision->fresh();
    }

    private function marketHealthSnapshot(RatioBotConfig $config, array $context, array $indicatorSnapshot, array $dynamicThresholds): array
    {
        $priceTimestamp = $context['price_timestamp'] ?? null;
        $priceAgeSeconds = null;
        if ($priceTimestamp instanceof \DateTimeInterface) {
            $priceAgeSeconds = now()->diffInSeconds($priceTimestamp);
        } elseif (is_numeric($priceTimestamp)) {
            $priceAgeSeconds = abs(((int) floor(microtime(true) * 1000)) - (int) $priceTimestamp) / 1000;
        }

        return [
            'feed_status' => $config->state?->feed_status,
            'latency_ms' => isset($context['latency_ms']) ? (int) $context['latency_ms'] : null,
            'price_age_seconds' => $context['price_age_seconds'] ?? $priceAgeSeconds,
            'price_source' => $context['price_source'] ?? null,
            'volume_ratio' => $indicatorSnapshot['volume_ratio'] ?? null,
            'spread_ratio' => $indicatorSnapshot['spread_ratio'] ?? null,
            'liquidity_notional' => $indicatorSnapshot['liquidity_notional'] ?? null,
            'market_mode' => $dynamicThresholds['market_mode'] ?? ($indicatorSnapshot['market_mode'] ?? null),
            'volatility_level' => $dynamicThresholds['volatility_level'] ?? ($indicatorSnapshot['volatility_level'] ?? null),
        ];
    }
}
