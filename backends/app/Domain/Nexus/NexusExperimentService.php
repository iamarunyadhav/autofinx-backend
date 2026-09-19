<?php

namespace App\Domain\Nexus;

use App\Domain\Trading\TimeframeRegistry;
use App\Domain\Trading\TradeForecastService;
use App\Models\MarketOhlc;
use App\Models\NexusEntry;
use Illuminate\Support\Carbon;

final class NexusExperimentService
{
    public function __construct(private readonly TradeForecastService $forecast, private readonly TimeframeRegistry $timeframes) {}

    /** Frozen, chronological forecast proxy. No order engine, tuning or writes outside Nexus. */
    public function evaluate(NexusEntry $entry): array
    {
        $p = $entry->payload;
        $seconds = $this->timeframes->seconds($p['timeframe']);
        $steps = intdiv($p['horizon_seconds'], $seconds);
        $rows = MarketOhlc::where('exchange', $p['exchange'])->where('symbol', $p['symbol'])->where('timeframe', $p['timeframe'])
            ->where('ts', '>=', Carbon::parse($p['from'])->utc())->where('ts', '<=', Carbon::parse($p['to'])->utc())->orderBy('ts')->limit(1001)->get();
        if ($steps < 1 || $p['horizon_seconds'] % $seconds !== 0 || $rows->count() > 1000) {
            return ['status' => 'DATA_REQUIRED', 'reason' => 'Use an aligned horizon and a window of at most 1000 candles.'];
        }
        if ($rows->count() < 160 + $steps) {
            return ['status' => 'DATA_REQUIRED', 'reason' => 'Insufficient historical candles.'];
        }
        foreach ($rows as $i => $row) {
            if ((float) $row->close <= 0 || Carbon::parse($row->ts)->addSeconds($seconds)->isFuture()
                || ($i > 0 && abs(Carbon::parse($rows[$i - 1]->ts)->diffInSeconds(Carbon::parse($row->ts)) - $seconds) > 0.5)) {
                return ['status' => 'DATA_REQUIRED', 'reason' => 'Invalid, incomplete or discontinuous candle history.'];
            }
        }
        $candles = $rows->map(fn ($r) => ['open' => (float) $r->open, 'high' => (float) $r->high,
            'low' => (float) $r->low, 'close' => (float) $r->close, 'volume' => (float) $r->volume])->all();
        $split = (int) floor(count($candles) * 0.6);
        $results = [];
        // Start after the split; history is context only. No fitting on holdout, non-overlapping outcomes.
        for ($i = max(100, $split); $i + $steps < count($candles); $i += $steps) {
            $forecast = $this->forecast->forecast(array_slice($candles, max(0, $i - 99), min(100, $i + 1)), $p['timeframe'], $p['horizon_seconds']);
            $side = $forecast['decision_trend'] ?? 'WAIT';
            if (! in_array($side, ['BUY', 'SELL'], true)) {
                continue;
            }
            $price = $candles[$i]['close'];
            $actual = $candles[$i + $steps]['close'];
            $gross = ($actual - $price) / $price * ($side === 'BUY' ? 1 : -1);
            $results[] = ['decision_at' => Carbon::parse($rows[$i]->ts)->addSeconds($seconds)->toIso8601String(),
                'expiry_at' => Carbon::parse($rows[$i + $steps]->ts)->addSeconds($seconds)->toIso8601String(),
                'side' => $side, 'direction_correct' => $gross > 0,
                'absolute_price_error' => abs($actual - $forecast['predicted_expiry_price']),
                'range_hit' => $actual >= $forecast['predicted_low'] && $actual <= $forecast['predicted_high'],
                'net_return_proxy' => $gross - $p['round_trip_cost_bps'] / 10000,
                'model_version' => $forecast['model_version']];
        }
        $n = count($results);

        return ['status' => $n >= 30 ? 'COMPLETED' : 'INSUFFICIENT_DATA', 'sample_size' => $n,
            'direction_accuracy' => $n ? count(array_filter($results, fn ($r) => $r['direction_correct'])) / $n : null,
            'price_mae' => $n ? array_sum(array_column($results, 'absolute_price_error')) / $n : null,
            'range_hit_rate' => $n ? count(array_filter($results, fn ($r) => $r['range_hit'])) / $n : null,
            'mean_net_return_proxy' => $n ? array_sum(array_column($results, 'net_return_proxy')) / $n : null,
            'dataset_hash' => hash('sha256', $rows->toJson()), 'split_at' => $rows[$split]->ts,
            'predictions' => $results, 'evidence_level' => 'B',
            'limitations' => ['OHLC close-to-close forecast proxy, not a fill simulation.',
                'Constant operator-supplied cost; funding and execution latency not simulated.',
                'SELL is hypothetical short exposure, not evidence of spot short execution.',
                'No automatic promotion to validated candidate.'],
        ];
    }
}
