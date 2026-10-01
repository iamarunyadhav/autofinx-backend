<?php

namespace App\Domain\Ml\Shadow;

use App\Models\MlCandle;
use App\Models\MlCandleRejection;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent persistence for validated candles and rejection records.
 *
 * Identity is provider + symbol + timeframe + open_time. Re-fetching an
 * identical closed candle is a no-op; a re-fetch whose values DIFFER from the
 * stored closed candle is never applied silently — the stored row is kept and
 * the conflict is recorded as a `duplicate` rejection for review.
 */
class MlCandleStore
{
    private const PRICE_TOLERANCE = 1e-9;

    /**
     * @param  array{accepted: list<array<string, int|float|null>>, rejections: list<array{open_time_ms:int, reason:string, payload:array<string, mixed>}>, forming_excluded:int}  $batch
     * @return array{written:int, unchanged:int, rejected:int, forming_excluded:int}
     */
    public function persist(string $symbol, string $timeframe, array $batch, string $source): array
    {
        $provider = (string) config('ml.provider', 'binance_futures');
        $result = ['written' => 0, 'unchanged' => 0, 'rejected' => 0, 'forming_excluded' => $batch['forming_excluded']];
        $accepted = $batch['accepted'];

        if ($accepted !== []) {
            $openTimes = array_column($accepted, 'open_time_ms');
            $existing = MlCandle::query()
                ->where('provider', $provider)->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->whereBetween('open_time_ms', [min($openTimes), max($openTimes)])
                ->get()->keyBy('open_time_ms');

            $now = now('UTC');
            $inserts = [];
            foreach ($accepted as $row) {
                $stored = $existing->get($row['open_time_ms']);
                if ($stored === null) {
                    $inserts[] = [
                        'provider' => $provider, 'symbol' => $symbol, 'timeframe' => $timeframe,
                        'open_time_ms' => $row['open_time_ms'], 'close_time_ms' => $row['close_time_ms'],
                        'open' => $row['open'], 'high' => $row['high'], 'low' => $row['low'], 'close' => $row['close'],
                        'volume' => $row['volume'], 'quote_volume' => $row['quote_volume'], 'trade_count' => $row['trade_count'],
                        'source' => $source, 'fetched_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ];

                    continue;
                }
                if ($this->sameValues($stored, $row)) {
                    $result['unchanged']++;

                    continue;
                }
                $this->reject($provider, $symbol, $timeframe, (int) $row['open_time_ms'], KlineBatchValidator::REASON_DUPLICATE, [
                    'detail' => 'conflicting_refetch_kept_stored_row',
                    'stored' => ['open' => $stored->open, 'high' => $stored->high, 'low' => $stored->low, 'close' => $stored->close, 'volume' => $stored->volume],
                    'incoming' => array_intersect_key($row, array_flip(['open', 'high', 'low', 'close', 'volume'])),
                ]);
                $result['rejected']++;
            }

            foreach (array_chunk($inserts, 500) as $chunk) {
                // insertOrIgnore keeps a concurrent live sync and backfill
                // safe: whichever writes first wins, the other is a no-op.
                $result['written'] += DB::table('ml_candles')->insertOrIgnore($chunk);
            }

            // A gap that has now been filled by a real closed candle is resolved.
            MlCandleRejection::query()
                ->where('provider', $provider)->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('reason', KlineBatchValidator::REASON_MISSING_INTERVAL)->where('resolved', false)
                ->whereIn('open_time_ms', $openTimes)
                ->update(['resolved' => true, 'updated_at' => now('UTC')]);
        }

        foreach ($batch['rejections'] as $rejection) {
            if ($rejection['reason'] === KlineBatchValidator::REASON_MISSING_INTERVAL
                && MlCandle::query()->where('provider', $provider)->where('symbol', $symbol)->where('timeframe', $timeframe)
                    ->where('open_time_ms', $rejection['open_time_ms'])->exists()) {
                continue;
            }
            $this->reject($provider, $symbol, $timeframe, $rejection['open_time_ms'], $rejection['reason'], $rejection['payload']);
            $result['rejected']++;
        }

        return $result;
    }

    /** @return array{open_time_ms:int, close:float}|null */
    public function previousCandle(string $symbol, string $timeframe, int $beforeOpenMs): ?array
    {
        $row = MlCandle::query()
            ->where('provider', (string) config('ml.provider', 'binance_futures'))
            ->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('open_time_ms', '<', $beforeOpenMs)
            ->orderByDesc('open_time_ms')->first(['open_time_ms', 'close']);

        return $row ? ['open_time_ms' => (int) $row->open_time_ms, 'close' => (float) $row->close] : null;
    }

    public function latestOpenTime(string $symbol, string $timeframe): ?int
    {
        $value = MlCandle::query()
            ->where('provider', (string) config('ml.provider', 'binance_futures'))
            ->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->max('open_time_ms');

        return $value === null ? null : (int) $value;
    }

    /** @param array<string, mixed> $payload */
    private function reject(string $provider, string $symbol, string $timeframe, int $openTimeMs, string $reason, array $payload): void
    {
        $existing = MlCandleRejection::query()
            ->where('provider', $provider)->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('open_time_ms', $openTimeMs)->where('reason', $reason)->first();
        if ($existing) {
            $existing->forceFill(['occurrences' => $existing->occurrences + 1, 'payload' => $payload])->save();

            return;
        }
        MlCandleRejection::query()->create([
            'provider' => $provider, 'symbol' => $symbol, 'timeframe' => $timeframe,
            'open_time_ms' => $openTimeMs, 'reason' => $reason, 'payload' => $payload,
        ]);
    }

    /** @param array<string, int|float|null> $row */
    private function sameValues(MlCandle $stored, array $row): bool
    {
        foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
            $a = (float) $stored->{$key};
            $b = (float) $row[$key];
            if (abs($a - $b) > self::PRICE_TOLERANCE * max(1.0, abs($a))) {
                return false;
            }
        }

        return true;
    }
}
