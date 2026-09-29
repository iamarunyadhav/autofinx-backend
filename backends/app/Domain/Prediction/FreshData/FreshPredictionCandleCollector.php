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
        return match ($timeframe) { '1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600, '4h' => 14400, default => throw new InvalidArgumentException('Unsupported timeframe.') };
    }
}
