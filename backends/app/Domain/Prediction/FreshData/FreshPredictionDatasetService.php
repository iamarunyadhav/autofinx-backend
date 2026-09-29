<?php

namespace App\Domain\Prediction\FreshData;

use App\Models\PredictionFreshDataset;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class FreshPredictionDatasetService
{
    public const SUPPORTED_TIMEFRAMES = ['1m', '5m', '15m', '1h', '4h'];

    public function start(string $name, array $symbols, array $timeframes, ?Carbon $startedAt = null): PredictionFreshDataset
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            throw new InvalidArgumentException('Dataset name is required.');
        }

        $symbols = array_values(array_unique(array_filter(array_map(
            static fn (mixed $symbol): string => strtoupper(trim((string) $symbol)), $symbols
        ))));
        $timeframes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $timeframe): string => strtolower(trim((string) $timeframe)), $timeframes
        ))));

        if ($symbols === [] || $timeframes === []) {
            throw new InvalidArgumentException('At least one symbol and timeframe are required.');
        }
        if (array_diff($timeframes, self::SUPPORTED_TIMEFRAMES) !== []) {
            throw new InvalidArgumentException('Unsupported timeframe requested.');
        }

        return PredictionFreshDataset::query()->create([
            'name' => $name,
            'exchange' => 'binance_futures',
            'symbols' => $symbols,
            'timeframes' => $timeframes,
            'started_at' => $startedAt ?? now('UTC'),
            'status' => 'COLLECTING',
            'metadata' => [
                'source' => 'binance_futures_canonical_rest',
                'closed_candles_only' => true,
                'execution' => false,
                'influence' => false,
            ],
        ]);
    }

    /** @param array<string, mixed> $audit */
    public function seal(PredictionFreshDataset $dataset, array $audit): PredictionFreshDataset
    {
        if ($dataset->status !== 'COLLECTING') {
            throw new InvalidArgumentException('Only a collecting dataset can be sealed.');
        }
        if (($audit['ready_pairs'] ?? 0) !== ($audit['total_pairs'] ?? -1) || ($audit['total_pairs'] ?? 0) === 0) {
            throw new InvalidArgumentException('Dataset quality audit has not approved every requested symbol/timeframe pair.');
        }

        $metadata = (array) $dataset->metadata;
        $metadata['sealed_at'] = now('UTC')->toIso8601String();
        $metadata['audit'] = $audit;
        $dataset->forceFill(['status' => 'SEALED', 'metadata' => $metadata])->save();

        return $dataset->refresh();
    }
}
