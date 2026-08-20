<?php

namespace App\Console\Commands;

use App\Domain\Market\MarketOhlcBackfillService;
use App\Models\RatioBotConfig;
use Illuminate\Console\Command;
use Throwable;

class MarketOhlcBackfillCommand extends Command
{
    protected $signature = 'market:ohlc-backfill
        {--exchange=binance : binance|binance_futures}
        {--symbol= : Single symbol, e.g. BTCUSDT (requires --timeframe)}
        {--timeframe=1h : Binance kline interval}
        {--limit=500 : Historical candles to fetch (max 1000)}
        {--bot= : Backfill for one ratio bot config id}
        {--all-ratio-bots : Backfill for every enabled ratio bot config}
        {--force : Backfill even if the DB already has >=30 rows}';

    protected $description = 'One-off/manual historical market_ohlc backfill from Binance public REST klines.';

    public function handle(MarketOhlcBackfillService $backfill): int
    {
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');

        if ($botId = $this->option('bot')) {
            $config = RatioBotConfig::find($botId);
            if (! $config) {
                $this->error("Ratio bot config {$botId} not found.");
                return self::FAILURE;
            }

            return $this->runOne($backfill, $config, $limit, $force);
        }

        if ($this->option('all-ratio-bots')) {
            $configs = RatioBotConfig::where('enabled', true)->get();
            $exit = self::SUCCESS;
            foreach ($configs as $config) {
                if ($this->runOne($backfill, $config, $limit, $force) !== self::SUCCESS) {
                    $exit = self::FAILURE;
                }
            }
            return $exit;
        }

        $symbol = $this->option('symbol');
        if (! $symbol) {
            $this->error('Pass --symbol=... (with --timeframe), or --bot=..., or --all-ratio-bots.');
            return self::FAILURE;
        }

        $exchange = (string) $this->option('exchange');
        $timeframe = (string) $this->option('timeframe');
        $futures = $exchange === 'binance_futures';

        if (! $force && ! $backfill->needsBackfill($exchange, $symbol, $timeframe)) {
            $this->info("{$symbol} {$timeframe} already has sufficient history. Use --force to backfill anyway.");
            return self::SUCCESS;
        }

        try {
            $written = $backfill->backfill($exchange, $symbol, $timeframe, $limit, $futures);
            $this->info("Backfilled {$written} candles for {$symbol} {$timeframe} ({$exchange}).");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Backfill failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function runOne(MarketOhlcBackfillService $backfill, RatioBotConfig $config, int $limit, bool $force): int
    {
        if (! $force && ! $backfill->needsBackfillForConfig($config)) {
            $this->info("[{$config->id}] {$config->normalizedSymbol()} {$config->timeFrame()} already has sufficient history.");
            return self::SUCCESS;
        }

        try {
            $written = $backfill->backfillForConfig($config, $limit);
            $this->info("[{$config->id}] Backfilled {$written} candles for {$config->normalizedSymbol()} {$config->timeFrame()}.");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("[{$config->id}] Backfill failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
