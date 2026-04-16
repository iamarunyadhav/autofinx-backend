<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Core ratio-bot decision engine.
 *
 * Implements the stateful buy/sell cycle:
 *
 *  SELL trigger (when holding):
 *      sell_ratio = (current_price / last_buy_price) - 1
 *      if sell_ratio >= sell_threshold_ratio  →  SELL
 *
 *  BUY trigger (when flat):
 *      buy_ratio = (last_sell_price / current_price) - 1
 *      if buy_ratio >= buy_threshold_ratio    →  BUY
 *
 * All decisions are written to ratio_bot_decisions for full auditability.
 * This service is pure decision logic — it does NOT submit orders.
 * Execution is delegated to RatioBotExecutionService.
 */
class RatioBotDecisionService
{
    /**
     * Evaluate the current price against the active anchor and decide what to do.
     *
     * @return array{decision: string, ratio: float, anchor: float|null, reason: string, decision_record: RatioBotDecision}
     */
    public function evaluate(RatioBotConfig $config, float $currentPrice): array
    {
        $state = $config->state ?? $this->initState($config);
        $correlationId = Str::uuid()->toString();

        Log::info('[RatioBot] Evaluating', [
            'config_id'     => $config->id,
            'symbol'        => $config->symbol,
            'position_state'=> $state->position_state,
            'current_price' => $currentPrice,
            'correlation_id'=> $correlationId,
        ]);

        // Guard: blocked states
        if ($state->isBlocked()) {
            if ($state->inCooldown()) {
                return $this->record($config, $state, RatioBotDecision::DECISION_COOLDOWN, $currentPrice, null, 0, 0, $correlationId, 'bot in cooldown until ' . $state->cooldown_until);
            }
            return $this->record($config, $state, RatioBotDecision::DECISION_PAUSE, $currentPrice, null, 0, 0, $correlationId, 'bot blocked: ' . $state->block_reason_code);
        }

        // Guard: bot not enabled
        if (! $config->enabled) {
            return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, null, 0, 0, $correlationId, 'bot disabled');
        }

        // Guard: awaiting a pending execution result — do not stack orders
        if ($state->position_state === RatioBotState::STATE_AWAITING_EXECUTION) {
            return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, null, 0, 0, $correlationId, 'awaiting execution result for previous order');
        }

        // IDLE — no anchor yet, cannot auto-trade yet
        if ($state->isIdle()) {
            return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, null, 0, 0, $correlationId, 'idle — set initial anchor via manual buy');
        }

        // -------------------------------------------------------
        // HOLDING → check for SELL trigger
        // sell_ratio = (current_price / last_buy_price) - 1
        // -------------------------------------------------------
        if ($state->isHolding()) {
            $anchor = (float) $state->last_buy_price;
            if ($anchor <= 0) {
                return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, 0, $config->sell_threshold_ratio, $correlationId, 'invalid buy anchor (zero)');
            }

            $ratio = ($currentPrice / $anchor) - 1;
            $threshold = (float) $config->sell_threshold_ratio;

            Log::debug('[RatioBot] Sell check', [
                'config_id' => $config->id,
                'ratio'     => $ratio,
                'threshold' => $threshold,
                'anchor'    => $anchor,
            ]);

            if ($ratio >= $threshold) {
                return $this->record($config, $state, RatioBotDecision::DECISION_SELL, $currentPrice, $anchor, $ratio, $threshold, $correlationId,
                    "sell triggered: ratio {$ratio} >= threshold {$threshold} (buy anchor {$anchor})");
            }

            return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, $ratio, $threshold, $correlationId,
                "holding: ratio {$ratio} < sell threshold {$threshold}");
        }

        // -------------------------------------------------------
        // FLAT → check for BUY trigger
        // buy_ratio = (last_sell_price / current_price) - 1
        // -------------------------------------------------------
        if ($state->isFlat()) {
            $anchor = (float) $state->last_sell_price;
            if ($anchor <= 0) {
                return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, 0, $config->buy_threshold_ratio, $correlationId, 'invalid sell anchor (zero)');
            }

            $ratio = ($anchor / $currentPrice) - 1;
            $threshold = (float) $config->buy_threshold_ratio;

            Log::debug('[RatioBot] Buy check', [
                'config_id' => $config->id,
                'ratio'     => $ratio,
                'threshold' => $threshold,
                'anchor'    => $anchor,
            ]);

            if ($ratio >= $threshold) {
                return $this->record($config, $state, RatioBotDecision::DECISION_BUY, $currentPrice, $anchor, $ratio, $threshold, $correlationId,
                    "buy triggered: ratio {$ratio} >= threshold {$threshold} (sell anchor {$anchor})");
            }

            return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, $anchor, $ratio, $threshold, $correlationId,
                "flat: ratio {$ratio} < buy threshold {$threshold}");
        }

        // Fallback
        return $this->record($config, $state, RatioBotDecision::DECISION_HOLD, $currentPrice, null, 0, 0, $correlationId, 'unhandled state: ' . $state->position_state);
    }

    /**
     * Compute the current ratio display values (for UI / API without deciding).
     */
    public function computeCurrentRatios(RatioBotConfig $config, float $currentPrice): array
    {
        $state = $config->state;
        if (! $state) {
            return $this->emptyRatioDisplay($currentPrice);
        }

        $result = [
            'current_price'      => $currentPrice,
            'position_state'     => $state->position_state,
            'last_buy_price'     => $state->last_buy_price,
            'last_sell_price'    => $state->last_sell_price,
            'active_anchor_price'=> $state->active_anchor_price,
            'active_anchor_type' => $state->active_anchor_type,
            'sell_ratio'         => null,
            'buy_ratio'          => null,
            'sell_threshold'     => $config->sell_threshold_ratio,
            'buy_threshold'      => $config->buy_threshold_ratio,
            'sell_distance_pct'  => null,
            'buy_distance_pct'   => null,
            'position_value_quote'  => null,
            'unrealized_pnl'        => null,
        ];

        if ($state->isHolding() && $state->last_buy_price > 0) {
            $ratio = ($currentPrice / (float) $state->last_buy_price) - 1;
            $result['sell_ratio']        = round($ratio, 6);
            $result['sell_distance_pct'] = round(((float) $config->sell_threshold_ratio - $ratio) * 100, 4);
            if ($state->last_buy_quantity > 0) {
                $posVal = $currentPrice * (float) $state->last_buy_quantity;
                $costBasis = (float) $state->last_buy_price * (float) $state->last_buy_quantity;
                $result['position_value_quote'] = round($posVal, 8);
                $result['unrealized_pnl']       = round($posVal - $costBasis, 8);
            }
        }

        if ($state->isFlat() && $state->last_sell_price > 0) {
            $ratio = ((float) $state->last_sell_price / $currentPrice) - 1;
            $result['buy_ratio']        = round($ratio, 6);
            $result['buy_distance_pct'] = round(((float) $config->buy_threshold_ratio - $ratio) * 100, 4);
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function record(
        RatioBotConfig $config,
        RatioBotState $state,
        string $decision,
        float $price,
        ?float $anchor,
        float $ratio,
        float $threshold,
        string $correlationId,
        string $reason
    ): array {
        $record = RatioBotDecision::create([
            'ratio_bot_config_id'   => $config->id,
            'correlation_id'        => $correlationId,
            'decision'              => $decision,
            'evaluated_price'       => $price,
            'anchor_price'          => $anchor,
            'anchor_type'           => $state->active_anchor_type,
            'computed_ratio'        => $ratio,
            'threshold_used'        => $threshold,
            'position_state_before' => $state->position_state,
            'position_state_after'  => $state->position_state,
            'execution_status'      => $decision === RatioBotDecision::DECISION_BUY || $decision === RatioBotDecision::DECISION_SELL
                ? RatioBotDecision::EXEC_PENDING
                : RatioBotDecision::EXEC_SKIPPED,
            'reason'                => $reason,
            'decided_at'            => now(),
        ]);

        // Update last_seen_price and current_ratio on state
        $state->update([
            'last_seen_price'   => $price,
            'current_ratio'     => $ratio,
            'last_evaluated_at' => now(),
        ]);

        return [
            'decision'        => $decision,
            'ratio'           => $ratio,
            'anchor'          => $anchor,
            'threshold'       => $threshold,
            'reason'          => $reason,
            'correlation_id'  => $correlationId,
            'decision_record' => $record,
        ];
    }

    private function initState(RatioBotConfig $config): RatioBotState
    {
        return RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state'      => RatioBotState::STATE_IDLE,
        ]);
    }

    private function emptyRatioDisplay(float $currentPrice): array
    {
        return [
            'current_price'       => $currentPrice,
            'position_state'      => 'idle',
            'last_buy_price'      => null,
            'last_sell_price'     => null,
            'active_anchor_price' => null,
            'active_anchor_type'  => null,
            'sell_ratio'          => null,
            'buy_ratio'           => null,
            'sell_threshold'      => null,
            'buy_threshold'       => null,
            'sell_distance_pct'   => null,
            'buy_distance_pct'    => null,
            'position_value_quote'=> null,
            'unrealized_pnl'      => null,
        ];
    }
}
