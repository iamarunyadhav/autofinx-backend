<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;

class TrailingProfitService
{
    public function resolve(RatioBotConfig $config, ?RatioBotState $state, float $currentPrice, array $dynamicThresholds = []): array
    {
        $params = (array) ($config->strategy_params ?? []);
        $profitLockEnabled = (bool) ($params['profit_lock_enabled'] ?? true);
        $trailingEnabled = (bool) ($params['use_trailing_take_profit'] ?? $config->usesTrailingRatio());
        $pullbackRatio = max(
            0.0,
            (float) ($dynamicThresholds['pullback_ratio'] ?? 0),
            $this->ratioFromPct($config->selling_loss_ratio)
        );
        $reboundRatio = max(
            0.0,
            (float) ($dynamicThresholds['rebound_ratio'] ?? 0),
            $this->ratioFromPct($config->buying_loss_ratio)
        );
        $trailingTakeProfit = max(
            0.0,
            (float) ($dynamicThresholds['trailing_take_profit_ratio'] ?? 0)
        );
        $stopLossRatio = max(
            0.0,
            (float) ($dynamicThresholds['stop_loss_ratio'] ?? 0),
            $this->ratioFromPct($config->stop_loss_pct)
        );

        $sellPeakRatio = $state?->sell_peak_ratio !== null ? (float) $state->sell_peak_ratio : null;
        $buyTroughRatio = $state?->buy_trough_ratio !== null ? (float) $state->buy_trough_ratio : null;

        $lockedProfitRatio = null;
        $profitLocked = false;
        if ($profitLockEnabled && $trailingEnabled && $sellPeakRatio !== null && $pullbackRatio > 0) {
            $lockedProfitRatio = max(0.0, $sellPeakRatio - $pullbackRatio);
            $profitLocked = $lockedProfitRatio > 0;
        }

        return [
            'trailing_enabled' => $trailingEnabled,
            'profit_lock_enabled' => $profitLockEnabled,
            'trailing_take_profit_ratio' => round($trailingTakeProfit, 6),
            'pullback_ratio' => round($pullbackRatio, 6),
            'rebound_ratio' => round($reboundRatio, 6),
            'stop_loss_ratio' => round($stopLossRatio, 6),
            'profit_locked' => $profitLocked,
            'locked_profit_ratio' => $lockedProfitRatio !== null ? round($lockedProfitRatio, 6) : null,
            'current_peak_price' => $state?->sell_peak_price,
            'current_bottom_price' => $state?->buy_trough_price,
            'current_peak_ratio' => $state?->sell_peak_ratio,
            'current_bottom_ratio' => $state?->buy_trough_ratio,
            'current_price' => $currentPrice,
        ];
    }

    private function ratioFromPct(mixed $value): float
    {
        $ratio = (float) ($value ?? 0);
        if ($ratio <= 0) {
            return 0.0;
        }

        return $ratio > 1 ? $ratio / 100 : $ratio;
    }
}
