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

    // Incremental runs only reach this far back for a schema that lags
    // (e.g. v2 while taker volume is still being backfilled). Older history
    // is covered by the full rebuild (after a backfill / daily retrain check),
    // so a lagging schema can never turn every 1-minute sync into a full scan.
    private const INCREMENTAL_MAX_LAG_MS = 2 * 86_400_000;

    /**
     * @param  bool  $full  Rescan the whole candle history (needed after a backfill
     *                      lands older candles than the newest existing anchor).
     * @return array{feature_rows:int, labels:int}
     */
    public function build(string $symbol, bool $full = false): array
    {
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $interval = MlCandleCollector::intervalMs($timeframe);
        $provider = (string) config('ml.provider', 'binance_futures');
        $schemas = $this->schemasToBuild();
        $maxLookback = max(array_map([ShadowFeatureBuilder::class, 'lookback'], $schemas));

        // Per schema: anchors already built (full mode) or the first anchor
        // still to build (incremental mode).
        $existing = array_fill_keys($schemas, []);
        $minAnchor = array_fill_keys($schemas, 0);
        $cursor = 0;
        if ($full) {
            foreach ($schemas as $schema) {
                $existing[$schema] = array_flip(MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
                    ->where('feature_schema_version', $schema)->pluck('anchor_time_ms')->map(fn ($v) => (int) $v)->all());
            }
        } else {
            $last = [];
            foreach ($schemas as $schema) {
                $value = MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
                    ->where('feature_schema_version', $schema)->max('anchor_time_ms');
                $last[$schema] = $value === null ? null : (int) $value;
            }
            $newest = max(array_map(fn ($v) => $v ?? 0, $last));
            if ($newest > 0) {
                $floor = $newest - self::INCREMENTAL_MAX_LAG_MS;
                foreach ($schemas as $schema) {
                    $minAnchor[$schema] = max($last[$schema] ?? $newest, $floor) + $interval;
                }
                $cursor = max(0, min($minAnchor) - ($maxLookback + 1) * $interval);
            }
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
                ->get(['open_time_ms', 'open', 'high', 'low', 'close', 'volume', 'taker_buy_volume']);
            if ($candles->isEmpty()) {
                break;
            }

            $rows = [];
            $now = now('UTC');
            foreach ($candles as $candle) {
                $c = [
                    'open_time_ms' => (int) $candle->open_time_ms, 'open' => (float) $candle->open, 'high' => (float) $candle->high,
                    'low' => (float) $candle->low, 'close' => (float) $candle->close, 'volume' => (float) $candle->volume,
                    'taker_buy_volume' => $candle->taker_buy_volume === null ? null : (float) $candle->taker_buy_volume,
                ];
                $previous = $window === [] ? null : $window[count($window) - 1];
                $run = $previous !== null && $c['open_time_ms'] - $previous['open_time_ms'] === $interval ? $run + 1 : 1;
                $window[] = $c;
                if (count($window) > $maxLookback) {
                    array_shift($window);
                }
                if ($run < ShadowFeatureBuilder::LOOKBACK) {
                    continue;
                }

                $anchorTime = $c['open_time_ms'] + $interval;
                $built = false;
                foreach ($schemas as $schema) {
                    if ($anchorTime < $minAnchor[$schema] || isset($existing[$schema][$anchorTime])) {
                        continue;
                    }
                    // Needs its whole lookback contiguous (run) and its taker data.
                    if ($run < ShadowFeatureBuilder::lookback($schema) || ! ShadowFeatureBuilder::satisfies($window, $schema)) {
                        continue;
                    }
                    $rows[] = [
                        'symbol' => $symbol, 'timeframe' => $timeframe, 'anchor_time_ms' => $anchorTime,
                        'anchor_close' => $c['close'], 'feature_schema_version' => $schema,
                        'features' => json_encode($this->builder->build($window, $interval, $schema), JSON_THROW_ON_ERROR),
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                    $built = true;
                }
                if ($built) {
                    $newAnchors[$anchorTime] = ['anchor_time_ms' => $anchorTime, 'anchor_close' => $c['close']];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                $written += DB::table('ml_feature_rows')->insertOrIgnore($chunk);
            }

            $cursor = (int) $candles->last()->open_time_ms + 1;
            if ($candles->count() < self::CANDLE_CHUNK) {
                break;
            }
        }

        // Labels are schema-independent (a future return does not depend on
        // which features describe the anchor): one label per anchor x horizon.
        $labels = $this->createLabels($symbol, $timeframe, array_values($newAnchors));
        if ($full) {
            $labels += $this->createMissingLabels($symbol, $timeframe);
        }

        return ['feature_rows' => $written, 'labels' => $labels];
    }

    /**
     * Schemas worth building: v1 (the cheapest, shortest-lookback schema — it
     * defines the anchor/label set from the 64th candle on), the training
     * schema, and every schema a live champion is served (so an older
     * champion keeps getting its own inputs). Unused schemas are not built.
     *
     * @return list<string>
     */
    public function schemasToBuild(): array
    {
        $championSchemas = DB::table('ml_champions as c')->join('ml_models as m', 'm.id', '=', 'c.model_id')
            ->distinct()->pluck('m.feature_schema_version')->all();
        $wanted = [ShadowFeatureBuilder::SCHEMA_V1, ShadowFeatureBuilder::SCHEMA_VERSION, ...$championSchemas];

        return array_values(array_filter(
            array_keys(ShadowFeatureBuilder::SCHEMAS),
            static fn (string $schema): bool => in_array($schema, $wanted, true),
        ));
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
                        // Informational only; labels are shared by all schemas.
                        'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_V1,
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
            // The schema with the most rows covers the widest anchor set (a
            // longer-lookback schema starts later; v2+ also need taker volume).
            $counts = MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->selectRaw('feature_schema_version, count(*) as total')->groupBy('feature_schema_version')
                ->pluck('total', 'feature_schema_version')->map(fn ($v) => (int) $v)->all();
            if ($counts === [] || $labelCount >= max($counts)) {
                continue;
            }
            $widest = (string) array_search(max($counts), $counts, true);
            MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('feature_schema_version', $widest)
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
