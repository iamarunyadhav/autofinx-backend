<?php

namespace Tests\Unit\Ml;

use Illuminate\Support\Facades\DB;

trait MlShadowTestHelpers
{
    protected const FIVE_MIN = 300_000;

    protected function configureMlForTests(): void
    {
        config([
            'ml.enabled' => true,
            'ml.symbols' => ['BTCUSDT', 'ETHUSDT'],
            'ml.horizons_minutes' => [15, 60, 240],
            'ml.binance.backfill_page_sleep_ms' => 0,
            'future_trade.binance_rest.min_interval_ms' => 0,
            'ml.training.artifact_dir' => storage_path('framework/testing/ml-shadow/models'),
            'ml.training.export_dir' => storage_path('framework/testing/ml-shadow/exports'),
            'ml.training.champion_dir' => storage_path('framework/testing/ml-shadow/champions'),
        ]);
    }

    /** Raw Binance Futures kline row (closed unless $closeMs says otherwise). */
    protected function kline(int $openMs, float $close = 100.0, ?int $closeMs = null, ?float $open = null, ?float $high = null, ?float $low = null, float $volume = 10.0): array
    {
        $open ??= $close;
        $high ??= max($open, $close) * 1.001;
        $low ??= min($open, $close) * 0.999;

        return [$openMs, (string) $open, (string) $high, (string) $low, (string) $close, (string) $volume,
            $closeMs ?? $openMs + self::FIVE_MIN - 1, '1000', 42, '5', '500', '0'];
    }

    /** Insert $count contiguous closed 5m candles straight into ml_candles. */
    protected function seedCandles(string $symbol, int $firstOpenMs, int $count, ?callable $price = null): void
    {
        $price ??= static fn (int $i): float => 100 + sin($i / 7) * 2 + $i * 0.01;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $close = $price($i);
            $open = $i === 0 ? $close : $price($i - 1);
            $rows[] = [
                'provider' => 'binance_futures', 'symbol' => $symbol, 'timeframe' => '5m',
                'open_time_ms' => $firstOpenMs + $i * self::FIVE_MIN, 'close_time_ms' => $firstOpenMs + ($i + 1) * self::FIVE_MIN - 1,
                'open' => $open, 'high' => max($open, $close) * 1.001, 'low' => min($open, $close) * 0.999, 'close' => $close,
                'volume' => 10 + ($i % 5), 'quote_volume' => null, 'trade_count' => null,
                'source' => 'test', 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ml_candles')->insert($chunk);
        }
    }

    protected function alignedOpen(int $candlesAgo): int
    {
        $now = now('UTC')->getTimestampMs();

        return intdiv($now, self::FIVE_MIN) * self::FIVE_MIN - $candlesAgo * self::FIVE_MIN;
    }
}
