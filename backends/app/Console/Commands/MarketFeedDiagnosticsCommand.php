<?php

namespace App\Console\Commands;

use App\Domain\Market\BinanceFuturesStreamRouter;
use App\Domain\Market\LiveCandleCacheService;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\MarketData\Enums\StreamType;
use App\Domain\MarketData\MarketFreshnessService;
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

    public function handle(MarketPriceCacheService $prices, LiveCandleCacheService $candles, MarketFreshnessService $freshness): int
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
        // For futures this is the aggregate compatibility file (merged
        // across both transport lanes by MarketWsConsumerCommand); the
        // lane-specific breakdown below reads each lane's own file directly.
        $heartbeatPath = storage_path("framework/autofinx-market-ws-heartbeat-{$marketType}.json");
        $heartbeatRaw = is_file($heartbeatPath) ? @file_get_contents($heartbeatPath) : false;
        $heartbeat = is_string($heartbeatRaw) ? json_decode($heartbeatRaw, true) : null;
        $symbols = is_array($worker) ? (array) ($worker['subscriptions'] ?? []) : [];
        $timeframe = (string) $this->option('timeframe');
        $nowMs = (int) floor(microtime(true) * 1000);
        // Binance spot/futures symbols are CRYPTO market-type instruments for
        // freshness-rule purposes (see InstrumentMapper's default and
        // RealtimeMarketFeedService::ingest()'s own marketCategory default);
        // this command is Binance-only, so this is not a guess specific to
        // one symbol.
        $candleRules = $freshness->rules('CRYPTO', StreamType::CANDLE);
        $rows = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper((string) $symbol);
            $quote = $prices->getLiveFeedSnapshot($exchange, $symbol, 15);
            $candle = $candles->stats($exchange, $symbol, $timeframe);
            $eventTs = (int) ($candle['latest_event_timestamp_ms'] ?? 0);
            $openCandleAgeMs = $eventTs > 0 ? max(0, $nowMs - $eventTs) : null;
            $quoteFresh = (bool) ($quote['fresh'] ?? false);
            $candleHealth = $this->candleHealthState($openCandleAgeMs, $candleRules);

            $rows[] = [
                'symbol' => $symbol,
                'redis_quote_age_seconds' => $quote['age_seconds'] ?? null,
                'quote_event_lag_ms' => $quote['event_lag_ms'] ?? null,
                'quote_fresh' => $quoteFresh,
                'open_candle_age_ms' => $openCandleAgeMs,
                'candle_cache_size' => $candle['size'],
                'latest_candle_closed' => $candle['latest_is_closed'],
                'last_open_kline_at' => $candle['last_open_kline_at'] ?? null,
                'last_closed_kline_at' => $candle['last_closed_kline_at'] ?? null,
                // Reporting-only distinction between quote and candle health
                // (2026-09-22 transport hotfix, item 11): a fresh quote no
                // longer implies a fresh candle. This does NOT gate any
                // trading/execution decision -- it is diagnostics output
                // only. See BinanceFuturesStreamRouter's docblock for why
                // these could diverge in the first place.
                'candle_health' => $candleHealth,
                'status' => ! $quoteFresh ? 'DEGRADED' : ($candleHealth === 'LIVE' ? 'HEALTHY' : 'DEGRADED'),
            ];
        }
        $result = [
            'worker' => $worker,
            'transport' => is_array($heartbeat) ? ($heartbeat['transport'] ?? null) : null,
            'reconnect_count' => is_array($heartbeat) ? ($heartbeat['reconnect_count'] ?? null) : null,
            'market_type' => $marketType,
            'timeframe' => $timeframe,
            'symbols' => $rows,
        ];

        if ($marketType === 'futures') {
            $result['lanes'] = $this->futuresLaneBreakdown();
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Symbol', 'Quote age', 'Event lag', 'Fresh', 'Open candle age', 'Cache', 'Candle health', 'Status'],
                array_map(fn ($row) => [
                    $row['symbol'],
                    $row['redis_quote_age_seconds'],
                    $row['quote_event_lag_ms'],
                    $row['quote_fresh'] ? 'yes' : 'no',
                    $row['open_candle_age_ms'],
                    $row['candle_cache_size'],
                    $row['candle_health'],
                    $row['status'],
                ], $rows)
            );

            if ($marketType === 'futures') {
                $lanes = $result['lanes'];
                $this->table(
                    ['Lane', 'Status', 'Streams', 'Reconnects', 'Age (s)'],
                    array_map(fn (string $lane, array $data) => [
                        $lane,
                        $data['worker']['status'] ?? 'MISSING',
                        implode(',', (array) ($data['streams'] ?? [])),
                        $data['reconnect_count'] ?? 'n/a',
                        $data['age_seconds'] ?? 'n/a',
                    ], array_keys($lanes), $lanes)
                );
            }
        }
        return self::SUCCESS;
    }

    /**
     * @param  array{expected_interval_ms:int,degraded_after_ms:int,stale_after_ms:int}  $rules
     */
    private function candleHealthState(?int $ageMs, array $rules): string
    {
        if ($ageMs === null) {
            return 'STALE';
        }
        if ($ageMs >= $rules['stale_after_ms']) {
            return 'STALE';
        }
        if ($ageMs >= $rules['degraded_after_ms']) {
            return 'DEGRADED';
        }

        return 'LIVE';
    }

    /** @return array<string, array<string, mixed>> */
    private function futuresLaneBreakdown(): array
    {
        $result = [];
        foreach (BinanceFuturesStreamRouter::lanes() as $lane) {
            $workerRaw = null;
            try {
                $workerRaw = Redis::get("autofinx:market-feed:binance:futures:{$lane}:worker");
            } catch (\Throwable) {
                // Redis already checked above; a transient failure here just
                // means this lane's breakdown reports as missing.
            }
            $worker = is_string($workerRaw) ? json_decode($workerRaw, true) : null;

            $path = MarketWsConsumerCommand::heartbeatPathFor('futures', $lane);
            $raw = is_file($path) ? @file_get_contents($path) : false;
            $heartbeat = is_string($raw) ? json_decode($raw, true) : null;
            $epoch = is_array($heartbeat) ? (int) ($heartbeat['last_message_epoch'] ?? 0) : 0;

            $result[$lane] = [
                'worker' => $worker,
                'streams' => is_array($worker) ? ($worker['streams'] ?? []) : (is_array($heartbeat) ? ($heartbeat['streams'] ?? []) : []),
                'reconnect_count' => is_array($heartbeat) ? ($heartbeat['reconnect_count'] ?? null) : null,
                'transport' => is_array($heartbeat) ? ($heartbeat['transport'] ?? null) : null,
                'age_seconds' => $epoch > 0 ? max(0, time() - $epoch) : null,
                'heartbeat_file' => is_file($path),
            ];
        }

        return $result;
    }
}
