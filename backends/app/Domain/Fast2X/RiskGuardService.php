<?php

namespace App\Domain\Fast2X;

use App\Models\Fast2XConfig;

class RiskGuardService
{
    public function __construct(
        private readonly TradeFrequencyService $frequency,
        private readonly DuplicateOrderGuard $duplicates,
        private readonly LatencyMonitorService $latency,
    ) {}

    public function validateBeforeTrade(Fast2XConfig $config, array $context): array
    {
        $settings = $config->mergedSettings();
        $state = $config->state;

        $checks = [
            [(bool) ($settings['emergencyStop'] ?? false) === false, 'Emergency stop is active'],
            [in_array((string) $state?->status, ['Running'], true), 'Bot is not running'],
            [($config->paper_trading || $config->enable_live_trading) && ! (!$config->paper_trading && !$config->enable_live_trading), 'Paper/live mode invalid'],
            [(string) $state?->websocket_status === 'Connected', 'WebSocket is disconnected'],
            [((int) ($context['tickAgeMs'] ?? 999999)) <= 3000, 'Price tick is stale'],
            [((float) ($context['spreadPercent'] ?? 0)) <= (float) ($settings['maxAllowedSlippagePercent'] ?? 0.15), 'Spread too wide'],
            [((float) ($context['slippagePercent'] ?? 0)) <= (float) ($settings['maxAllowedSlippagePercent'] ?? 0.15), 'Slippage too high'],
            [($context['side'] ?? '') === 'SELL' || ($context['side'] ?? '') === 'BUY' || ((float) ($context['expectedNetProfitPercent'] ?? 999)) >= (float) ($settings['minimumProfitAfterFeesPercent'] ?? 0.1), 'Expected profit lower than fees and slippage'],
            [abs((float) ($state?->daily_loss ?? 0)) <= (float) ($settings['maxDailyLossPercent'] ?? 3), 'Daily loss limit exceeded'],
            [($context['exchangeHealthy'] ?? true) === true, 'Exchange health is not OK'],
        ];

        foreach ($checks as [$allowed, $reason]) {
            if (! $allowed) {
                return ['allowed' => false, 'reason' => $reason];
            }
        }

        $latency = $this->latency->validate($config, (int) ($context['latencyMs'] ?? 0), $settings);
        if (! $latency['allowed']) {
            return $latency;
        }

        $frequency = $this->frequency->validate($config, $settings);
        if (! $frequency['allowed']) {
            return $frequency;
        }

        $duplicate = $this->duplicates->validate($config, (string) ($context['signalKey'] ?? ''));
        if (! $duplicate['allowed']) {
            return $duplicate;
        }

        if (! $config->paper_trading && (! $config->enable_live_trading || ! config('fast2x.enable_live_trading'))) {
            return ['allowed' => false, 'reason' => 'Live trading is blocked unless explicitly enabled'];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
