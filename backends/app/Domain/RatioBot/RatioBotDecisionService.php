<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use DateTimeInterface;
use Illuminate\Support\Carbon;
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

        if (($state->isHolding() || $state->isPartialHolding()) && $state->last_buy_price > 0) {
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
        if (! in_array($decision, [RatioBotDecision::DECISION_BUY, RatioBotDecision::DECISION_SELL], true)
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
