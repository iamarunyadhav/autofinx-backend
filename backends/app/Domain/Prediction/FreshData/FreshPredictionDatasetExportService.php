<?php

namespace App\Domain\Prediction\FreshData;

use App\Models\PredictionFreshDataset;
use App\Models\PredictionFreshDatasetCandle;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class FreshPredictionDatasetExportService
{
    /** @return array{path:string, manifest_path:string, rows:int, sha256:string} */
    public function export(PredictionFreshDataset $dataset, string $symbol, string $timeframe): array
    {
        if ($dataset->status !== 'SEALED') {
            throw new InvalidArgumentException('Only a sealed, audited fresh dataset may be exported for training.');
        }

        $symbol = strtoupper(trim($symbol));
        $timeframe = strtolower(trim($timeframe));
        if (! in_array($symbol, (array) $dataset->symbols, true) || ! in_array($timeframe, (array) $dataset->timeframes, true)) {
            throw new InvalidArgumentException('Requested symbol/timeframe does not belong to this dataset.');
        }

        $directory = storage_path('app/prediction-fresh-exports/'.$dataset->id);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create isolated training export directory.');
        }
        $path = $directory.'/'.$symbol.'-'.$timeframe.'.jsonl';
        $temporaryPath = $path.'.tmp';
        $handle = fopen($temporaryPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to write isolated training export.');
        }

        $rows = 0;
        try {
            foreach (PredictionFreshDatasetCandle::query()
                ->where('dataset_id', $dataset->id)->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->orderBy('open_time')->cursor() as $candle) {
                    fwrite($handle, json_encode([
                        'ts' => Carbon::parse($candle->getRawOriginal('open_time'), 'UTC')->toIso8601String(),
                        'open' => (float) $candle->open,
                        'high' => (float) $candle->high,
                        'low' => (float) $candle->low,
                        'close' => (float) $candle->close,
                        'volume' => (float) $candle->volume,
                    ], JSON_THROW_ON_ERROR)."\n");
                    $rows++;
            }
        } finally {
            fclose($handle);
        }
        if ($rows === 0) {
            @unlink($temporaryPath);
            throw new InvalidArgumentException('Dataset pair has no candles to export.');
        }
        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to finalize isolated training export.');
        }

        $sha256 = hash_file('sha256', $path);
        $manifestPath = $path.'.manifest.json';
        file_put_contents($manifestPath, json_encode([
            'schema_version' => 1,
            'dataset_id' => $dataset->id,
            'dataset_name' => $dataset->name,
            'exchange' => $dataset->exchange,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'rows' => $rows,
            'sha256' => $sha256,
            'dataset_sealed_at' => $dataset->metadata['sealed_at'] ?? null,
            'execution' => false,
            'influence' => false,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ['path' => $path, 'manifest_path' => $manifestPath, 'rows' => $rows, 'sha256' => $sha256];
    }
}
