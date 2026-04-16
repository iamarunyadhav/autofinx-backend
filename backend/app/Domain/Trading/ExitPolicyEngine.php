<?php

namespace App\Domain\Trading;

use App\Domain\Trading\Data\ExitDecision;
use App\Models\Position;
use App\Models\PositionExitState;

class ExitPolicyEngine
{
    public function evaluate(Position $position, PositionExitState $state, array $marketSnapshot, array $context = []): ExitDecision
    {
        $policy = (array) ($state->exit_policy_snapshot ?? []);
        $qty = $this->positionQty($position);
        $correlationId = (string) ($context['correlation_id'] ?? '');
        $exchangeHealthy = (bool) ($context['ops']['exchange_healthy'] ?? false);
        $priceFresh = (bool) ($marketSnapshot['fresh'] ?? false);
        $price = max(0.0, (float) ($marketSnapshot['price'] ?? 0));

        if ($qty <= 0 || $state->status === PositionExitState::STATUS_CLOSED) {
            return $this->decision(ExitDecision::ACTION_NO_ACTION, 'position_closed', $policy, $marketSnapshot, $correlationId);
        }

        if ($state->status === PositionExitState::STATUS_UNCERTAIN) {
            return $this->decision(ExitDecision::ACTION_OPERATOR_REVIEW, 'uncertain_position_state', $policy, $marketSnapshot, $correlationId, true);
        }

        if ($state->managed_mode === PositionExitState::MODE_REVIEW_REQUIRED) {
            return $this->decision(ExitDecision::ACTION_OPERATOR_REVIEW, 'review_required', $policy, $marketSnapshot, $correlationId, true);
        }

        if ($state->managed_mode === PositionExitState::MODE_PAUSED) {
            return $this->decision(ExitDecision::ACTION_BLOCKED, 'exit_automation_paused', $policy, $marketSnapshot, $correlationId);
        }

        if ($state->managed_mode === PositionExitState::MODE_CLOSING || $state->status === PositionExitState::STATUS_CLOSING || $state->active_exit_order_id) {
            return $this->decision(ExitDecision::ACTION_BLOCKED, 'exit_order_in_flight', $policy, $marketSnapshot, $correlationId);
        }

        if (! $exchangeHealthy) {
            return $this->decision(ExitDecision::ACTION_BLOCKED, 'exchange_unhealthy', $policy, $marketSnapshot, $correlationId);
        }

        if ((bool) ($context['ops']['breaker_severe'] ?? false) && ! empty($policy['emergency_exit_on_breaker'])) {
            return $this->decision(
                ExitDecision::ACTION_FULL_EXIT,
                'emergency_exit_breaker',
                $policy,
                $marketSnapshot,
                $correlationId,
                false,
                false,
                'full',
                $qty,
                null,
                ['force_exit' => true]
            );
        }

        if ((bool) ($context['state']['pause_active'] ?? false) && ! empty($policy['force_exit_on_pause'])) {
            return $this->decision(
                ExitDecision::ACTION_FULL_EXIT,
                'force_exit_on_pause',
                $policy,
                $marketSnapshot,
                $correlationId,
                false,
                false,
                'full',
                $qty,
                null,
                ['force_exit' => true]
            );
        }

        if ((bool) ($context['loss']['daily_loss_breached'] ?? false) && ! empty($policy['force_exit_on_max_daily_loss'])) {
            return $this->decision(
                ExitDecision::ACTION_FULL_EXIT,
                'force_exit_on_daily_loss',
                $policy,
                $marketSnapshot,
                $correlationId,
                false,
                false,
                'full',
                $qty,
                null,
                ['force_exit' => true]
            );
        }

        if (! $priceFresh || $price <= 0) {
            if ($this->timeStopReached($state->time_stop_at)) {
                return $this->decision(
                    ExitDecision::ACTION_FULL_EXIT,
                    'time_stop_reached_without_fresh_price',
                    $policy,
                    $marketSnapshot,
                    $correlationId,
                    false,
                    false,
                    'full',
                    $qty
                );
            }

            return $this->decision(ExitDecision::ACTION_BLOCKED, 'stale_market_data', $policy, $marketSnapshot, $correlationId);
        }

        $entryPrice = max(0.0, (float) ($policy['entry_price'] ?? 0));
        $highWater = max(
            $entryPrice,
            (float) ($state->trailing_high_water_mark ?? 0),
            $price
        );
        $trailingStopPrice = null;
        $stateUpdates = [];

        if (! empty($policy['trailing_stop_enabled']) && ! empty($policy['trailing_stop_pct'])) {
            $trailingStopPrice = $this->trailingStopPrice($policy, $highWater);
            if ($highWater > (float) ($state->trailing_high_water_mark ?? 0) + 1e-8) {
                $stateUpdates['trailing_high_water_mark'] = $highWater;
                $stateUpdates['trailing_stop_price'] = $trailingStopPrice;
            }
        }

        $stopLossPrice = $this->stopLossPrice($state, $policy);
        if ($stopLossPrice !== null && $price <= $stopLossPrice + 1e-8) {
            return $this->decision(
                ExitDecision::ACTION_FULL_EXIT,
                'stop_loss_triggered',
                $policy,
                $marketSnapshot,
                $correlationId,
                false,
                false,
                'full',
                $qty,
                null,
                [],
                $stateUpdates
            );
        }

        if ($trailingStopPrice !== null && $price <= $trailingStopPrice + 1e-8) {
            return $this->decision(
                ExitDecision::ACTION_FULL_EXIT,
                'trailing_stop_triggered',
                $policy,
                $marketSnapshot,
                $correlationId,
                false,
                false,
                'full',
                $qty,
                null,
                [],
                $stateUpdates
            );
        }

        $partial = $this->nextPartialExit($position, $state, $price, $policy);
        if ($partial) {
            return $this->decision(
                ExitDecision::ACTION_PARTIAL_EXIT,
                'partial_take_profit_triggered',
                $policy,
                $marketSnapshot,
                $correlationId,
                false,
                false,
                'qty',
                $partial['qty'],
                (string) $partial['level'],
                $partial,
                $stateUpdates
            );
        }

        $takeProfitPrice = max(0.0, (float) ($state->take_profit_price ?? ($policy['take_profit_price'] ?? 0)));
        if ($takeProfitPrice > 0 && $price >= $takeProfitPrice - 1e-8) {
            return $this->decision(
                ExitDecision::ACTION_FULL_EXIT,
                'take_profit_triggered',
                $policy,
                $marketSnapshot,
                $correlationId,
                false,
                false,
                'full',
                $qty,
                null,
                [],
                $stateUpdates
            );
        }

        if ($this->timeStopReached($state->time_stop_at)) {
            return $this->decision(
                ExitDecision::ACTION_FULL_EXIT,
                'time_stop_reached',
                $policy,
                $marketSnapshot,
                $correlationId,
                false,
                false,
                'full',
                $qty,
                null,
                [],
                $stateUpdates
            );
        }

        return $this->decision(
            ExitDecision::ACTION_NO_ACTION,
            'no_exit_action',
            $policy,
            $marketSnapshot,
            $correlationId,
            false,
            false,
            null,
            null,
            null,
            [],
            $stateUpdates
        );
    }

    private function nextPartialExit(Position $position, PositionExitState $state, float $price, array $policy): ?array
    {
        if (empty($policy['partial_take_profit_enabled'])) {
            return null;
        }

        $levels = array_values((array) ($policy['partial_take_profit_levels'] ?? []));
        $sizes = array_values((array) ($policy['partial_take_profit_sizes'] ?? []));
        if ($levels === [] || count($levels) !== count($sizes)) {
            return null;
        }

        $entryPrice = max(0.0, (float) ($policy['entry_price'] ?? 0));
        $initialQty = max(0.0, (float) ($policy['initial_qty'] ?? $this->positionQty($position)));
        $remainingQty = $this->positionQty($position);
        $positionValue = $remainingQty * $price;
        $minValue = max(0.0, (float) ($policy['minimum_position_value_for_partial'] ?? 0));
        if ($entryPrice <= 0 || $initialQty <= 0 || $remainingQty <= 0 || ($minValue > 0 && $positionValue < $minValue)) {
            return null;
        }

        $progress = (array) ($state->partial_progress ?? []);
        $consumedLevels = array_map('strval', (array) ($progress['consumed_levels'] ?? []));

        foreach ($levels as $index => $level) {
            $levelKey = (string) $level;
            if (in_array($levelKey, $consumedLevels, true)) {
                continue;
            }

            $targetPrice = round($entryPrice * (1 + ((float) $level / 100)), 8);
            if ($price + 1e-8 < $targetPrice) {
                return null;
            }

            $levelQty = round($initialQty * ((float) ($sizes[$index] ?? 0) / 100), 8);
            $exitQty = min($remainingQty, $levelQty);
            if ($exitQty <= 0) {
                return null;
            }

            return [
                'level' => $levelKey,
                'target_price' => $targetPrice,
                'qty' => $exitQty,
                'requested_level_qty' => $levelQty,
            ];
        }

        return null;
    }

    private function stopLossPrice(PositionExitState $state, array $policy): ?float
    {
        $price = (float) ($state->stop_loss_price ?? 0);
        if ($price > 0) {
            return $price;
        }

        $entry = max(0.0, (float) ($policy['entry_price'] ?? 0));
        $pct = max(0.0, (float) ($policy['default_stop_loss_pct'] ?? 0));
        if ($entry <= 0 || $pct <= 0) {
            return null;
        }

        return round($entry * (1 - ($pct / 100)), 8);
    }

    private function trailingStopPrice(array $policy, float $highWaterMark): ?float
    {
        $pct = max(0.0, (float) ($policy['trailing_stop_pct'] ?? 0));
        if ($pct <= 0 || $highWaterMark <= 0) {
            return null;
        }

        return round($highWaterMark * (1 - ($pct / 100)), 8);
    }

    private function timeStopReached(mixed $timeStopAt): bool
    {
        if (! $timeStopAt) {
            return false;
        }

        return now()->greaterThanOrEqualTo($timeStopAt);
    }

    private function positionQty(Position $position): float
    {
        return abs((float) ($position->qty ?? $position->quantity ?? 0));
    }

    private function decision(
        string $action,
        string $reasonCode,
        array $policySnapshot,
        array $marketSnapshot,
        string $correlationId,
        bool $requiresReview = false,
        bool $requiresCancelReplace = false,
        ?string $quantityMode = null,
        ?float $quantity = null,
        ?string $targetLevel = null,
        array $meta = [],
        array $stateUpdates = []
    ): ExitDecision {
        return new ExitDecision(
            action: $action,
            reasonCode: $reasonCode,
            quantityMode: $quantityMode,
            quantity: $quantity,
            targetLevel: $targetLevel,
            requiresReview: $requiresReview,
            requiresCancelReplace: $requiresCancelReplace,
            policySnapshot: $policySnapshot,
            marketSnapshot: $marketSnapshot,
            correlationId: $correlationId,
            stateUpdates: $stateUpdates,
            meta: $meta,
        );
    }
}
