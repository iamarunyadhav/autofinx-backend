<?php

namespace App\Domain\MarketIntelligence\V5\Application;

use App\Domain\MarketOpportunityBrain\Contracts\MarketSnapshotProvider;
use DateTimeImmutable;

/** Causal, non-probabilistic observations. Never produces execution permission. */
final class MarketAssessmentService
{
    public function __construct(private readonly MarketSnapshotProvider $snapshots) {}

    public function assess(string $symbol, DateTimeImmutable $asOf): array
    {
        $horizons = [];
        foreach (['15m' => 15, '1h' => 60, '4h' => 240, '24h' => 1440] as $timeframe => $minutes) {
            $providerTimeframe = $timeframe === '24h' ? '1d' : $timeframe;
            $candles = $this->snapshots->closedCandles($symbol, $providerTimeframe, $asOf, 32);
            if (count($candles) < 2) { $horizons[$timeframe] = ['status' => 'insufficient_closed_candles']; continue; }
            $first = $candles[0]->close; $last = $candles[array_key_last($candles)]->close;
            $move = $first == 0.0 ? 0.0 : ($last / $first) - 1.0;
            $ranges = array_map(static fn ($c) => ($c->high - $c->low) / max($c->close, 0.00000001), $candles);
            $volatility = array_sum($ranges) / count($ranges);
            $direction = abs($move) <= max(0.001, $volatility * 0.25) ? 'SIDEWAYS' : ($move > 0 ? 'UP' : 'DOWN');
            $lastCandle = $candles[array_key_last($candles)];
            $taker = null;
            if ($lastCandle->takerVolume !== null && $lastCandle->volume > 0.0) {
                $ratio = $lastCandle->takerVolume / $lastCandle->volume;
                $taker = ['buy_ratio' => max(0.0, min(1.0, $ratio)), 'imbalance' => (2.0 * $ratio) - 1.0,
                    'buy_volume' => $lastCandle->takerVolume, 'sell_volume_estimate' => max(0.0, $lastCandle->volume - $lastCandle->takerVolume)];
            }
            $horizons[$timeframe] = ['status' => 'observed', 'direction' => $direction, 'observed_move' => $move,
                'volatility' => $volatility, 'closed_candle_count' => count($candles),
                'last_closed_at' => $lastCandle->closeTimeUtc, 'reference_close' => $lastCandle->close, 'taker_pressure' => $taker];
        }
        $observed = $horizons['15m'];
        return ['symbol' => $symbol, 'horizons' => $horizons,
            'direction' => $observed['direction'] ?? 'SIDEWAYS',
            'regime' => (($observed['volatility'] ?? 0) > 0.02 ? 'HIGH_VOLATILITY' : 'NORMAL_VOLATILITY'),
            'supporting_evidence' => ['closed_candle_trend', 'range_volatility'],
            'conflicting_evidence' => [],
            'taker_pressure_status' => collect($horizons)->contains(fn ($h) => ($h['taker_pressure'] ?? null) !== null) ? 'available_from_closed_kline' : 'unavailable_from_current_provider',
            'funding_status' => 'unavailable', 'open_interest_status' => 'unavailable',
            'calibration_status' => 'UNCALIBRATED_RESEARCH_ONLY', 'execution_authorized' => false];
    }
}
