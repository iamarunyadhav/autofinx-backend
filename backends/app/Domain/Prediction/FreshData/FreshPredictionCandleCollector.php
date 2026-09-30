<?php

namespace App\Domain\Prediction\FreshData;

use App\Models\PredictionFreshDataset;
use App\Models\PredictionFreshDatasetCandle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class FreshPredictionCandleCollector
{
    /** @return array{written:int, skipped_open:int, invalid:int} */
    public function collect(PredictionFreshDataset $dataset, int $limit = 1000): array
    {
        if ($dataset->status !== 'COLLECTING' || $dataset->exchange !== 'binance_futures') {
            throw new InvalidArgumentException('Dataset is not an active Binance Futures collector.');
        }

        $totals = ['written' => 0, 'skipped_open' => 0, 'invalid' => 0];
        foreach ((array) $dataset->symbols as $symbol) {
            foreach ((array) $dataset->timeframes as $timeframe) {
                $result = $this->collectPair($dataset, (string) $symbol, (string) $timeframe, $limit);
                foreach ($totals as $key => $value) $totals[$key] += $result[$key];
            }
        }
        return $totals;
    }

    /** @return array{requested:int, repaired:int, unavailable:int, invalid:int} */
    public function repairGaps(PredictionFreshDataset $dataset, int $maxGaps = 50): array
    {
        if ($dataset->status !== 'COLLECTING' || $dataset->exchange !== 'binance_futures') {
            throw new InvalidArgumentException('Dataset is not an active Binance Futures collector.');
        }

        $totals = ['requested' => 0, 'repaired' => 0, 'unavailable' => 0, 'invalid' => 0];
        foreach ((array) $dataset->symbols as $symbol) {
            foreach ((array) $dataset->timeframes as $timeframe) {
                if ($totals['requested'] >= $maxGaps) {
                    return $totals;
                }
                $result = $this->repairPairGaps($dataset, strtoupper((string) $symbol), strtolower((string) $timeframe), $maxGaps - $totals['requested']);
                foreach ($totals as $key => $value) {
                    $totals[$key] += $result[$key];
                }
            }
        }

        return $totals;
    }

    /** @return array{written:int, skipped_open:int, invalid:int} */
    private function collectPair(PredictionFreshDataset $dataset, string $symbol, string $timeframe, int $limit): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtolower($timeframe);
        if ($timeframe === '45m') {
            return $this->collectDerived45mPair($dataset, $symbol, $limit);
        }
        $seconds = $this->intervalSeconds($timeframe);
        $last = PredictionFreshDatasetCandle::query()
            ->where('dataset_id', $dataset->id)->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->latest('open_time')->value('open_time');
        $start = $last
            ? Carbon::parse($last, 'UTC')->addSeconds($seconds)
            : Carbon::parse($dataset->getRawOriginal('started_at'), 'UTC');
        $response = Http::timeout(15)->get(rtrim((string) config('services.binance.futures_api_url', 'https://fapi.binance.com'), '/').'/fapi/v1/klines', [
            'symbol' => $symbol, 'interval' => $timeframe, 'startTime' => $start->getTimestampMs(), 'limit' => max(1, min(1000, $limit)),
        ]);
        if ($response->failed()) {
            throw new RuntimeException("Binance Futures candle collection failed for {$symbol} {$timeframe}: {$response->status()}");
        }

        $result = ['written' => 0, 'skipped_open' => 0, 'invalid' => 0];
        $nowMs = now('UTC')->getTimestampMs();
        foreach ((array) $response->json() as $row) {
            if (! is_array($row) || count($row) < 7) { $result['invalid']++; continue; }
            $openMs = (int) $row[0];
            $closeMs = (int) $row[6];
            if ($closeMs >= $nowMs) { $result['skipped_open']++; continue; }
            if ($openMs % ($seconds * 1000) !== 0 || $closeMs <= $openMs || ! $this->validOhlcv($row)) { $result['invalid']++; continue; }
            $openTime = Carbon::createFromTimestampMs($openMs, 'UTC');
            if ($openTime->lt($dataset->started_at)) continue;
            $candle = PredictionFreshDatasetCandle::query()->firstOrCreate([
                'dataset_id' => $dataset->id, 'symbol' => $symbol, 'timeframe' => $timeframe, 'open_time' => $openTime,
            ], [
                'exchange' => 'binance_futures', 'close_time' => Carbon::createFromTimestampMs($closeMs, 'UTC'),
                'open' => (float) $row[1], 'high' => (float) $row[2], 'low' => (float) $row[3], 'close' => (float) $row[4], 'volume' => (float) $row[5],
                'source' => 'binance_futures_canonical_rest', 'received_at' => now('UTC'),
                'quality' => ['closed' => true, 'aligned' => true, 'source_interval' => $timeframe],
            ]);
            if ($candle->wasRecentlyCreated) $result['written']++;
        }
        return $result;
    }

    /** @return array{requested:int, repaired:int, unavailable:int, invalid:int} */
    private function repairPairGaps(PredictionFreshDataset $dataset, string $symbol, string $timeframe, int $remaining): array
    {
        $result = ['requested' => 0, 'repaired' => 0, 'unavailable' => 0, 'invalid' => 0];
        if ($timeframe === '45m') {
            return $this->repairDerived45mGaps($dataset, $symbol, $remaining);
        }
        $seconds = $this->intervalSeconds($timeframe);
        $previous = null;
        $rows = PredictionFreshDatasetCandle::query()
            ->where('dataset_id', $dataset->id)->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->orderBy('open_time')->get(['open_time']);

        foreach ($rows as $row) {
            $open = Carbon::parse($row->getRawOriginal('open_time'), 'UTC');
            if ($previous) {
                $expected = $previous->copy()->addSeconds($seconds);
                while ($expected->lt($open) && $result['requested'] < $remaining) {
                    $result['requested']++;
                    $outcome = $this->fetchSingleClosedCandle($dataset, $symbol, $timeframe, $expected);
                    $result[$outcome]++;
                    $expected->addSeconds($seconds);
                }
            }
            $previous = $open;
            if ($result['requested'] >= $remaining) {
                break;
            }
        }

        return $result;
    }

    private function fetchSingleClosedCandle(PredictionFreshDataset $dataset, string $symbol, string $timeframe, Carbon $expectedOpen): string
    {
        $seconds = $this->intervalSeconds($timeframe);
        $response = Http::timeout(15)->get(rtrim((string) config('services.binance.futures_api_url', 'https://fapi.binance.com'), '/').'/fapi/v1/klines', [
            'symbol' => $symbol,
            'interval' => $timeframe,
            'startTime' => $expectedOpen->getTimestampMs(),
            'endTime' => $expectedOpen->copy()->addSeconds($seconds)->getTimestampMs() - 1,
            'limit' => 1,
        ]);
        if ($response->failed()) {
            throw new RuntimeException("Binance Futures gap repair failed for {$symbol} {$timeframe}: {$response->status()}");
        }
        $row = $response->json()[0] ?? null;
        if (! is_array($row) || count($row) < 7) {
            return 'unavailable';
        }
        $openMs = (int) $row[0];
        $closeMs = (int) $row[6];
        if ($openMs !== $expectedOpen->getTimestampMs() || $closeMs >= now('UTC')->getTimestampMs() || ! $this->validOhlcv($row)) {
            return 'invalid';
        }

        $candle = PredictionFreshDatasetCandle::query()->firstOrCreate([
            'dataset_id' => $dataset->id, 'symbol' => $symbol, 'timeframe' => $timeframe, 'open_time' => $expectedOpen,
        ], [
            'exchange' => 'binance_futures', 'close_time' => Carbon::createFromTimestampMs($closeMs, 'UTC'),
            'open' => (float) $row[1], 'high' => (float) $row[2], 'low' => (float) $row[3], 'close' => (float) $row[4], 'volume' => (float) $row[5],
            'source' => 'binance_futures_canonical_rest', 'received_at' => now('UTC'),
            'quality' => ['closed' => true, 'aligned' => true, 'source_interval' => $timeframe, 'repaired' => true],
        ]);

        return $candle->wasRecentlyCreated ? 'repaired' : 'unavailable';
    }

    private function validOhlcv(array $row): bool
    {
        $open = (float) $row[1]; $high = (float) $row[2]; $low = (float) $row[3]; $close = (float) $row[4]; $volume = (float) $row[5];
        return $open > 0 && $high >= max($open, $close) && $low <= min($open, $close) && $low > 0 && $volume >= 0;
    }

    private function intervalSeconds(string $timeframe): int
    {
        return match ($timeframe) { '1m' => 60, '5m' => 300, '15m' => 900, '45m' => 2700, '1h' => 3600, '4h' => 14400, default => throw new InvalidArgumentException('Unsupported timeframe.') };
    }

    /**
     * Binance Futures has no native 45m kline. This makes the interval only
     * from three consecutive, fully closed 15m candles on a UTC 45m boundary.
     * The resulting row remains in the isolated fresh dataset and cannot
     * influence market_ohlc or any execution path.
     *
     * @return array{written:int, skipped_open:int, invalid:int}
     */
    private function collectDerived45mPair(PredictionFreshDataset $dataset, string $symbol, int $limit): array
    {
        $last = PredictionFreshDatasetCandle::query()
            ->where('dataset_id', $dataset->id)->where('symbol', $symbol)->where('timeframe', '45m')
            ->latest('open_time')->value('open_time');
        $start = $last
            ? Carbon::parse($last, 'UTC')->addMinutes(45)
            : $this->next45MinuteBoundary(Carbon::parse($dataset->getRawOriginal('started_at'), 'UTC'));

        $response = Http::timeout(15)->get(rtrim((string) config('services.binance.futures_api_url', 'https://fapi.binance.com'), '/').'/fapi/v1/klines', [
            'symbol' => $symbol,
            'interval' => '15m',
            'startTime' => $start->getTimestampMs(),
            'limit' => max(3, min(1000, $limit * 3)),
        ]);
        if ($response->failed()) {
            throw new RuntimeException("Binance Futures derived 45m candle collection failed for {$symbol}: {$response->status()}");
        }

        $result = ['written' => 0, 'skipped_open' => 0, 'invalid' => 0];
        foreach (array_chunk((array) $response->json(), 3) as $rows) {
            $outcome = $this->storeDerived45mCandle($dataset, $symbol, $rows);
            $result[$outcome]++;
        }

        return $result;
    }

    /** @return array{requested:int, repaired:int, unavailable:int, invalid:int} */
    private function repairDerived45mGaps(PredictionFreshDataset $dataset, string $symbol, int $remaining): array
    {
        $result = ['requested' => 0, 'repaired' => 0, 'unavailable' => 0, 'invalid' => 0];
        $previous = null;
        $rows = PredictionFreshDatasetCandle::query()
            ->where('dataset_id', $dataset->id)->where('symbol', $symbol)->where('timeframe', '45m')
            ->orderBy('open_time')->get(['open_time']);

        foreach ($rows as $row) {
            $open = Carbon::parse($row->getRawOriginal('open_time'), 'UTC');
            if ($previous) {
                $expected = $previous->copy()->addMinutes(45);
                while ($expected->lt($open) && $result['requested'] < $remaining) {
                    $result['requested']++;
                    $outcome = $this->fetchAndStoreDerived45mCandle($dataset, $symbol, $expected);
                    $result[$outcome]++;
                    $expected->addMinutes(45);
                }
            }
            $previous = $open;
            if ($result['requested'] >= $remaining) {
                break;
            }
        }

        return $result;
    }

    private function fetchAndStoreDerived45mCandle(PredictionFreshDataset $dataset, string $symbol, Carbon $open): string
    {
        $response = Http::timeout(15)->get(rtrim((string) config('services.binance.futures_api_url', 'https://fapi.binance.com'), '/').'/fapi/v1/klines', [
            'symbol' => $symbol,
            'interval' => '15m',
            'startTime' => $open->getTimestampMs(),
            'endTime' => $open->copy()->addMinutes(45)->getTimestampMs() - 1,
            'limit' => 3,
        ]);
        if ($response->failed()) {
            throw new RuntimeException("Binance Futures derived 45m gap repair failed for {$symbol}: {$response->status()}");
        }

        return $this->storeDerived45mCandle($dataset, $symbol, (array) $response->json());
    }

    /** @param array<int, mixed> $rows */
    private function storeDerived45mCandle(PredictionFreshDataset $dataset, string $symbol, array $rows): string
    {
        if (count($rows) !== 3 || ! array_is_list($rows)) {
            return 'invalid';
        }
        $rows = array_values($rows);
        if (array_filter($rows, fn (mixed $row): bool => ! is_array($row) || count($row) < 7 || ! $this->validOhlcv($row)) !== []) {
            return 'invalid';
        }

        $openMs = (int) $rows[0][0];
        $nowMs = now('UTC')->getTimestampMs();
        $sourceIntervalMs = 900_000;
        foreach ($rows as $index => $row) {
            if ((int) $row[0] !== $openMs + ($index * $sourceIntervalMs)) {
                return 'invalid';
            }
        }
        $closeMs = (int) $rows[2][6];
        if ($openMs % 2_700_000 !== 0) {
            return 'invalid';
        }
        if ($closeMs >= $nowMs) {
            return 'skipped_open';
        }

        $openTime = Carbon::createFromTimestampMs($openMs, 'UTC');
        if ($openTime->lt(Carbon::parse($dataset->getRawOriginal('started_at'), 'UTC'))) {
            return 'invalid';
        }
        $candle = PredictionFreshDatasetCandle::query()->firstOrCreate([
            'dataset_id' => $dataset->id, 'symbol' => $symbol, 'timeframe' => '45m', 'open_time' => $openTime,
        ], [
            'exchange' => 'binance_futures', 'close_time' => Carbon::createFromTimestampMs($closeMs, 'UTC'),
            'open' => (float) $rows[0][1], 'high' => max(array_map(static fn (array $row): float => (float) $row[2], $rows)),
            'low' => min(array_map(static fn (array $row): float => (float) $row[3], $rows)), 'close' => (float) $rows[2][4],
            'volume' => array_sum(array_map(static fn (array $row): float => (float) $row[5], $rows)),
            'source' => 'binance_futures_derived_15m_closed', 'received_at' => now('UTC'),
            'quality' => ['closed' => true, 'aligned' => true, 'source_interval' => '15m', 'derived_timeframe' => '45m', 'source_candle_count' => 3],
        ]);

        return $candle->wasRecentlyCreated ? 'written' : 'unavailable';
    }

    private function next45MinuteBoundary(Carbon $at): Carbon
    {
        $seconds = $at->getTimestamp();
        return Carbon::createFromTimestamp((int) (ceil($seconds / 2700) * 2700), 'UTC');
    }
}
