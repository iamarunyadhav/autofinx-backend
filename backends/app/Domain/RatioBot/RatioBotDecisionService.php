<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
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
        $policy = $this->resolveRuntimePolicy($config, $context);

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

        if ($state->isHolding()) {
            return $this->evaluateHolding($config, $state, $currentPrice, $correlationId, $priceSource, $priceTimestamp, $policy);
        }

        if ($state->isFlat()) {
            return $this->evaluateFlat($config, $state, $currentPrice, $correlationId, $priceSource, $priceTimestamp, $policy);
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
        ];

        if ($state->isHolding() && $state->last_buy_price > 0) {
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
        $requiredUpdates = max(1, (int) ($config->trigger_confirm_updates ?? 1));
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
            ],
        ];
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
