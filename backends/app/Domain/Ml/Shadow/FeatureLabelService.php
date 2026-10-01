<?php

namespace App\Domain\Ml\Shadow;

use App\Models\MlCandle;
use App\Models\MlFeatureRow;
use App\Models\MlLabel;
use Illuminate\Support\Facades\DB;

/**
 * Turns validated closed candles into causal feature rows and per-horizon
 * labels, and matures PENDING labels as their horizon passes.
 *
 * Anchor T = close time of the anchor candle. The label for horizon h is the
 * close of the candle that closes EXACTLY at T+h (open = T+h-interval). If
 * that candle does not exist the label stays PENDING — it is never filled
 * with a default and never counted as a wrong prediction.
 */
class FeatureLabelService
{
    private const CANDLE_CHUNK = 5000;

    private const LABEL_CHUNK = 2000;

    public function __construct(private readonly ShadowFeatureBuilder $builder) {}

    /**
     * @param  bool  $full  Rescan the whole candle history (needed after a backfill
     *                      lands older candles than the newest existing anchor).
     * @return array{feature_rows:int, labels:int}
     */
    public function build(string $symbol, bool $full = false): array
    {
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $interval = MlCandleCollector::intervalMs($timeframe);
        $schema = ShadowFeatureBuilder::SCHEMA_VERSION;
        $provider = (string) config('ml.provider', 'binance_futures');

        $existing = [];
        if ($full) {
            $existing = array_flip(MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('feature_schema_version', $schema)->pluck('anchor_time_ms')->map(fn ($v) => (int) $v)->all());
            $cursor = 0;
            $minAnchor = 0;
        } else {
            $lastAnchor = MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('feature_schema_version', $schema)->max('anchor_time_ms');
            $minAnchor = $lastAnchor === null ? 0 : (int) $lastAnchor + $interval;
            $cursor = $lastAnchor === null ? 0 : max(0, (int) $lastAnchor - (ShadowFeatureBuilder::LOOKBACK * $interval));
        }

        $window = [];
        $run = 0;
        $written = 0;
        $newAnchors = [];
        while (true) {
            $candles = MlCandle::query()
                ->where('provider', $provider)->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('open_time_ms', '>=', $cursor)
                ->orderBy('open_time_ms')->limit(self::CANDLE_CHUNK)
                ->get(['open_time_ms', 'open', 'high', 'low', 'close', 'volume']);
            if ($candles->isEmpty()) {
                break;
            }

            $rows = [];
            $now = now('UTC');
            foreach ($candles as $candle) {
                $c = [
                    'open_time_ms' => (int) $candle->open_time_ms, 'open' => (float) $candle->open, 'high' => (float) $candle->high,
                    'low' => (float) $candle->low, 'close' => (float) $candle->close, 'volume' => (float) $candle->volume,
                ];
                $previous = $window === [] ? null : $window[count($window) - 1];
                $run = $previous !== null && $c['open_time_ms'] - $previous['open_time_ms'] === $interval ? $run + 1 : 1;
                $window[] = $c;
                if (count($window) > ShadowFeatureBuilder::LOOKBACK) {
                    array_shift($window);
                }

                $anchorTime = $c['open_time_ms'] + $interval;
                if ($run < ShadowFeatureBuilder::LOOKBACK || $anchorTime < $minAnchor || isset($existing[$anchorTime])) {
                    continue;
                }
                $rows[] = [
                    'symbol' => $symbol, 'timeframe' => $timeframe, 'anchor_time_ms' => $anchorTime,
                    'anchor_close' => $c['close'], 'feature_schema_version' => $schema,
                    'features' => json_encode($this->builder->build($window, $interval), JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now,
                ];
                $newAnchors[] = ['anchor_time_ms' => $anchorTime, 'anchor_close' => $c['close']];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                $written += DB::table('ml_feature_rows')->insertOrIgnore($chunk);
            }

            $cursor = (int) $candles->last()->open_time_ms + 1;
            if ($candles->count() < self::CANDLE_CHUNK) {
                break;
            }
        }

        $labels = $this->createLabels($symbol, $timeframe, $newAnchors);
        if ($full) {
            $labels += $this->createMissingLabels($symbol, $timeframe);
        }

        return ['feature_rows' => $written, 'labels' => $labels];
    }

    /**
     * Fill PENDING labels whose exact target candle now exists.
     *
     * @return array<int, int> matured count per horizon
     */
    public function mature(string $symbol): array
    {
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $interval = MlCandleCollector::intervalMs($timeframe);
        $latestOpen = MlCandle::query()->where('provider', (string) config('ml.provider', 'binance_futures'))
            ->where('symbol', $symbol)->where('timeframe', $timeframe)->max('open_time_ms');
        $matured = [];
        foreach ($this->horizons() as $horizon) {
            $matured[$horizon] = 0;
            if ($latestOpen === null) {
                continue;
            }
            MlLabel::query()
                ->where('symbol', $symbol)->where('timeframe', $timeframe)->where('horizon_minutes', $horizon)
                ->where('status', 'PENDING')->where('target_time_ms', '<=', (int) $latestOpen + $interval)
                ->orderBy('id')
                ->chunkById(self::LABEL_CHUNK, function ($labels) use ($symbol, $timeframe, $interval, &$matured, $horizon): void {
                    $closes = $this->closesAt($symbol, $timeframe, $labels->map(fn (MlLabel $l) => (int) $l->target_time_ms - $interval)->all());
                    foreach ($labels as $label) {
                        $close = $closes[(int) $label->target_time_ms - $interval] ?? null;
                        if ($close === null) {
                            continue;
                        }
                        $label->forceFill([
                            'target_close' => $close,
                            'future_return' => $close / (float) $label->anchor_close - 1,
                            'status' => 'MATURE',
                            'matured_at' => now('UTC'),
                        ])->save();
                        $matured[$horizon]++;
                    }
                });
        }

        return $matured;
    }

    /** @return list<int> */
    public function horizons(): array
    {
        $interval = intdiv(MlCandleCollector::intervalMs((string) config('ml.base_timeframe', '5m')), 60_000);

        return array_values(array_filter(
            array_map('intval', (array) config('ml.horizons_minutes', [15, 60, 240])),
            static fn (int $h): bool => $h > 0 && $h % $interval === 0,
        ));
    }

    /** @param list<array{anchor_time_ms:int, anchor_close:float}> $anchors */
    private function createLabels(string $symbol, string $timeframe, array $anchors): int
    {
        $interval = MlCandleCollector::intervalMs($timeframe);
        $created = 0;
        foreach (array_chunk($anchors, self::LABEL_CHUNK) as $chunk) {
            $targets = [];
            foreach ($chunk as $anchor) {
                foreach ($this->horizons() as $horizon) {
                    $targets[] = $anchor['anchor_time_ms'] + $horizon * 60_000 - $interval;
                }
            }
            $closes = $this->closesAt($symbol, $timeframe, $targets);
            $now = now('UTC');
            $rows = [];
            foreach ($chunk as $anchor) {
                foreach ($this->horizons() as $horizon) {
                    $target = $anchor['anchor_time_ms'] + $horizon * 60_000;
                    $close = $closes[$target - $interval] ?? null;
                    $rows[] = [
                        'symbol' => $symbol, 'timeframe' => $timeframe, 'horizon_minutes' => $horizon,
                        'anchor_time_ms' => $anchor['anchor_time_ms'], 'target_time_ms' => $target,
                        'anchor_close' => $anchor['anchor_close'], 'target_close' => $close,
                        'future_return' => $close === null ? null : $close / $anchor['anchor_close'] - 1,
                        'status' => $close === null ? 'PENDING' : 'MATURE',
                        'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION,
                        'matured_at' => $close === null ? null : $now, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
            foreach (array_chunk($rows, 500) as $insert) {
                $created += DB::table('ml_labels')->insertOrIgnore($insert);
            }
        }

        return $created;
    }

    private function createMissingLabels(string $symbol, string $timeframe): int
    {
        $created = 0;
        foreach ($this->horizons() as $horizon) {
            $labelCount = MlLabel::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->where('horizon_minutes', $horizon)->count();
            $featureCount = MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('feature_schema_version', ShadowFeatureBuilder::SCHEMA_VERSION)->count();
            if ($labelCount >= $featureCount) {
                continue;
            }
            MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('feature_schema_version', ShadowFeatureBuilder::SCHEMA_VERSION)
                ->orderBy('id')->select(['id', 'anchor_time_ms', 'anchor_close'])
                ->chunkById(self::LABEL_CHUNK, function ($rows) use ($symbol, $timeframe, &$created): void {
                    $created += $this->createLabels($symbol, $timeframe, $rows->map(fn (MlFeatureRow $r) => [
                        'anchor_time_ms' => (int) $r->anchor_time_ms, 'anchor_close' => (float) $r->anchor_close,
                    ])->all());
                });
            break; // createLabels covers every horizon at once.
        }

        return $created;
    }

    /**
     * @param  list<int>  $openTimes
     * @return array<int, float>
     */
    private function closesAt(string $symbol, string $timeframe, array $openTimes): array
    {
        if ($openTimes === []) {
            return [];
        }

        return MlCandle::query()
            ->where('provider', (string) config('ml.provider', 'binance_futures'))
            ->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->whereBetween('open_time_ms', [min($openTimes), max($openTimes)])
            ->whereIn('open_time_ms', array_values(array_unique($openTimes)))
            ->pluck('close', 'open_time_ms')->map(fn ($v) => (float) $v)->all();
    }
}
