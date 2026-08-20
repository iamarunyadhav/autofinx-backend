<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;

class RatioBotRiskService
{
    public function preTradeBlockReason(RatioBotConfig $config, RatioBotDecision $decision, array $analysis = []): ?array
    {
        $state = $config->state;
        if (! $state) {
            return null;
        }

        $metadata = (array) ($decision->metadata ?? []);
        $marketHealth = (array) ($analysis['market_health'] ?? []);
        $indicatorConfirmation = (array) ($analysis['indicator_confirmation'] ?? []);
        $protectiveExit = (bool) ($metadata['protective_exit'] ?? false);
        if (! $protectiveExit && in_array((string) ($metadata['trigger'] ?? ''), ['stop_loss', 'time_stop'], true)) {
            $protectiveExit = true;
        }

        if ((bool) ($config->kill_switch ?? false)) {
            return $this->reason('kill_switch', 'Bot kill switch is active.', true);
        }

        if ($state->inCooldown()) {
            return $this->reason('cooldown', 'Bot cooldown is active.', false);
        }

        $priceTimestamp = $state->last_price_timestamp;
        if ($priceTimestamp && $priceTimestamp->diffInSeconds(now()) > $config->staleFeedWindowSeconds()) {
            return $this->reason('stale_feed', 'Latest market price is stale.', (bool) $config->pause_on_stale_feed);
        }

        if ((string) ($state->feed_status ?? '') === 'degraded') {
            return $this->reason('feed_degraded', 'Realtime feed is degraded.', (bool) $config->pause_on_stale_feed);
        }

        $priceAgeSeconds = (float) ($marketHealth['price_age_seconds'] ?? 0);
        if ($priceAgeSeconds > 0 && $priceAgeSeconds > $config->staleFeedWindowSeconds()) {
            return $this->reason('stale_feed', 'Market snapshot age exceeded stale feed window.', (bool) $config->pause_on_stale_feed, [
                'price_age_seconds' => $priceAgeSeconds,
                'max_age_seconds' => $config->staleFeedWindowSeconds(),
            ]);
        }

        $latencyLimit = max(0, (int) data_get($config->strategy_params ?? [], 'max_api_latency_ms', 0));
        $latencyMs = (float) ($marketHealth['latency_ms'] ?? 0);
        if ($latencyLimit > 0 && $latencyMs > $latencyLimit) {
            return $this->reason('api_latency_high', "API latency {$latencyMs}ms exceeded limit {$latencyLimit}ms.", false, [
                'latency_ms' => $latencyMs,
                'latency_limit_ms' => $latencyLimit,
            ]);
        }

        if ($this->duplicateSignal($state, $decision)) {
            return $this->reason('duplicate_signal', 'Same signal was already emitted recently.', false);
        }

        $maxTradesPerDay = (int) ($config->max_trades_per_day ?? 0);
        if ($maxTradesPerDay > 0 && (int) ($state->daily_trade_count ?? 0) >= $maxTradesPerDay) {
            return $this->reason('max_trades_per_day', 'Daily trade limit reached.', false);
        }

        $maxLoss = (float) ($config->max_daily_loss ?? 0);
        if ($maxLoss > 0 && (float) ($state->daily_loss_total ?? 0) >= $maxLoss) {
            return $this->reason('max_daily_loss', 'Daily loss guard reached.', true);
        }

        $maxConsecutiveLosses = (int) ($config->max_consecutive_losses ?? 0);
        if ($maxConsecutiveLosses > 0 && (int) ($state->consecutive_losses ?? 0) >= $maxConsecutiveLosses) {
            return $this->reason('max_consecutive_losses', 'Consecutive loss guard reached.', true);
        }

        if (! $protectiveExit) {
            $volumeRatio = (float) ($marketHealth['volume_ratio'] ?? 0);
            $minVolumeRatio = max(0.0, (float) data_get($config->strategy_params ?? [], 'min_volume_ratio', 0));
            if ($minVolumeRatio > 0 && $volumeRatio > 0 && $volumeRatio < $minVolumeRatio) {
                return $this->reason('low_volume', "Volume ratio {$volumeRatio} is below minimum {$minVolumeRatio}.", false, [
                    'volume_ratio' => $volumeRatio,
                    'min_volume_ratio' => $minVolumeRatio,
                ]);
            }

            $spreadRatio = (float) ($marketHealth['spread_ratio'] ?? 0);
            $maxSpreadRatio = max(0.0, (float) data_get($config->strategy_params ?? [], 'max_spread_ratio', 0));
            if ($maxSpreadRatio > 0 && $spreadRatio > $maxSpreadRatio) {
                return $this->reason('spread_too_high', "Spread proxy {$spreadRatio} exceeded max {$maxSpreadRatio}.", false, [
                    'spread_ratio' => $spreadRatio,
                    'max_spread_ratio' => $maxSpreadRatio,
                ]);
            }

            $liquidity = (float) ($marketHealth['liquidity_notional'] ?? 0);
            $minLiquidity = max(0.0, (float) data_get($config->strategy_params ?? [], 'min_liquidity_notional', 0));
            if ($minLiquidity > 0 && $liquidity > 0 && $liquidity < $minLiquidity) {
                return $this->reason('low_liquidity', "Liquidity proxy {$liquidity} is below minimum {$minLiquidity}.", false, [
                    'liquidity_notional' => $liquidity,
                    'min_liquidity_notional' => $minLiquidity,
                ]);
            }

            if ((bool) ($indicatorConfirmation['enabled'] ?? false) && ! (bool) ($indicatorConfirmation['ok'] ?? true)) {
                return $this->reason(
                    'indicator_confirmation_failed',
                    'Indicator confirmation failed: ' . implode(', ', (array) ($indicatorConfirmation['reasons'] ?? [])),
                    false,
                    ['indicator_confirmation' => $indicatorConfirmation]
                );
            }
        }

        return null;
    }

    /**
     * Pre-submit revalidation: compares a fresh reference price (fetched
     * immediately before order submission) against the price the decision
     * was originally evaluated at. This is a DIFFERENT concept from the
     * stale-feed/age checks above (those check how OLD the data is; this
     * checks how much the price has MOVED since the decision was made) —
     * mirrors RobotSessionService::entryPreflightBlock()'s drift guard.
     */
    public function priceDriftBlock(RatioBotConfig $config, float $decisionPrice, float $freshPrice): ?array
    {
        $maxDriftBps = $config->maxEntryPriceDriftBps();
        if ($maxDriftBps <= 0 || $decisionPrice <= 0 || $freshPrice <= 0) {
            return null;
        }

        $driftBps = abs($freshPrice - $decisionPrice) / $decisionPrice * 10000;
        if ($driftBps <= $maxDriftBps) {
            return null;
        }

        return $this->reason(
            'price_drift_exceeded',
            sprintf(
                'Fresh quote %.8f diverged %.2f bps from decision price %.8f (max %.2f bps).',
                $freshPrice,
                $driftBps,
                $decisionPrice,
                $maxDriftBps
            ),
            false, // transient — skip this submission, do not pause the whole bot
            [
                'decision_price' => $decisionPrice,
                'fresh_price' => $freshPrice,
                'drift_bps' => round($driftBps, 4),
                'max_drift_bps' => $maxDriftBps,
            ]
        );
    }

    private function duplicateSignal(mixed $state, RatioBotDecision $decision): bool
    {
        $signalHash = (string) data_get($decision->metadata, 'signal_hash', '');
        if ($signalHash === '' || (string) ($state->last_signal_hash ?? '') === '') {
            return false;
        }

        if ((string) data_get($state->runtime_meta, 'decision_id', '') === (string) $decision->id) {
            return false;
        }

        if ($signalHash !== (string) $state->last_signal_hash) {
            return false;
        }

        return $state->last_signal_at !== null && $state->last_signal_at->diffInSeconds(now()) <= 15;
    }

    private function reason(string $code, string $detail, bool $pause, array $meta = []): array
    {
        return [
            'code' => $code,
            'detail' => $detail,
            'pause' => $pause,
            'meta' => $meta,
        ];
    }
}
