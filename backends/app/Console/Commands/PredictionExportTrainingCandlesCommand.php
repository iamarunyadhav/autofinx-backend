<?php

namespace App\Console\Commands;

use App\Models\MarketOhlc;
use Illuminate\Console\Command;

class PredictionExportTrainingCandlesCommand extends Command
{
    protected $signature = 'prediction:export-training-candles
        {--exchange=binance : Exchange/provider key}
        {--symbol=BTCUSDT : Trading symbol}
        {--timeframe=5m : Closed candle timeframe}
        {--output=storage/app/prediction-training.csv : CSV output path}
        {--limit=10000 : Maximum candles to export}';

    protected $description = 'Export ordered closed MarketOhlc candles for isolated prediction training';

    public function handle(): int
    {
        $rows = MarketOhlc::query()->where('exchange', strtolower((string) $this->option('exchange')))
            ->where('symbol', strtoupper((string) $this->option('symbol')))
            ->where('timeframe', strtolower((string) $this->option('timeframe')))
            ->orderBy('ts')->limit(max(1, (int) $this->option('limit')))->get();
        if ($rows->count() < 30) {
            $this->error('Insufficient closed candles: at least 30 are required.');
            return self::FAILURE;
        }
        $path = (string) $this->option('output');
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\]/', $path)
            ? $path : base_path($path);
        $directory = dirname($absolute);
        if (! is_dir($directory)) mkdir($directory, 0750, true);
        $handle = fopen($absolute, 'wb');
        fputcsv($handle, ['timestamp', 'open', 'high', 'low', 'close', 'volume']);
        foreach ($rows as $row) fputcsv($handle, [$row->ts->getTimestamp(), $row->open, $row->high, $row->low, $row->close, $row->volume]);
        fclose($handle);
        $this->info(sprintf('Exported %d closed candles to %s', $rows->count(), $absolute));
        return self::SUCCESS;
    }
}
