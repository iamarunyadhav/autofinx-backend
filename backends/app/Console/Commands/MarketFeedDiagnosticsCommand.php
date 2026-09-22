<?php

namespace App\Console\Commands;

use App\Domain\Market\LiveCandleCacheService;
use App\Domain\Market\MarketPriceCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/** Read-only companion for market:ws-consume; safe to run on production. */
class MarketFeedDiagnosticsCommand extends Command
{
    protected $signature = 'market:feed-diagnostics
        {--market-type=futures : spot|futures}
        {--timeframe=1m}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Show deterministic per-symbol market feed freshness and cache telemetry.';

    public function handle(MarketPriceCacheService $prices, LiveCandleCacheService $candles): int
    {
        $marketType = strtolower((string) $this->option('market-type'));
        if (! in_array($marketType, ['spot', 'futures'], true)) {
            $this->error('market-type must be spot or futures.');
            return self::FAILURE;
        }
        $exchange = $marketType === 'futures' ? 'binance_futures' : 'binance';
        try {
            $workerRaw = Redis::get("autofinx:market-feed:binance:{$marketType}:worker");
        } catch (\Throwable $e) {
            $this->error('Redis unavailable: '.$e->getMessage());
            return self::FAILURE;
        }
        $worker = is_string($workerRaw) ? json_decode($workerRaw, true) : null;
        $heartbeatPath = storage_path("framework/autofinx-market-ws-heartbeat-{$marketType}.json");
        $heartbeatRaw = is_file($heartbeatPath) ? @file_get_contents($heartbeatPath) : false;
        $heartbeat = is_string($heartbeatRaw) ? json_decode($heartbeatRaw, true) : null;
        $symbols = is_array($worker) ? (array) ($worker['subscriptions'] ?? []) : [];
        $nowMs = (int) floor(microtime(true) * 1000);
        $rows = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper((string) $symbol);
            $quote = $prices->getLiveFeedSnapshot($exchange, $symbol, 15);
            $candle = $candles->stats($exchange, $symbol, (string) $this->option('timeframe'));
            $eventTs = (int) ($candle['latest_event_timestamp_ms'] ?? 0);
            $rows[] = [
                'symbol' => $symbol,
                'redis_quote_age_seconds' => $quote['age_seconds'] ?? null,
                'quote_event_lag_ms' => $quote['event_lag_ms'] ?? null,
                'quote_fresh' => $quote['fresh'] ?? false,
                'open_candle_age_ms' => $eventTs > 0 ? max(0, $nowMs - $eventTs) : null,
                'candle_cache_size' => $candle['size'],
                'latest_candle_closed' => $candle['latest_is_closed'],
            ];
        }
        $result = [
            'worker' => $worker,
            'transport' => is_array($heartbeat) ? ($heartbeat['transport'] ?? null) : null,
            'reconnect_count' => is_array($heartbeat) ? ($heartbeat['reconnect_count'] ?? null) : null,
            'market_type' => $marketType,
            'timeframe' => $this->option('timeframe'),
            'symbols' => $rows,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Symbol', 'Quote age', 'Event lag', 'Fresh', 'Open candle age', 'Cache'], array_map(fn ($row) => [$row['symbol'], $row['redis_quote_age_seconds'], $row['quote_event_lag_ms'], $row['quote_fresh'] ? 'yes' : 'no', $row['open_candle_age_ms'], $row['candle_cache_size']], $rows));
        }
        return self::SUCCESS;
    }
}
