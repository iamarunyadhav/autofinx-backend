<?php

namespace App\Domain\Market;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/** Read-only aggregate-trade pressure projection; never an execution input. */
class MarketPressureService
{
    private const WINDOWS = ['1m' => 60, '5m' => 300, '15m' => 900];
    private const MAX_BUCKETS = 900;
    private const MAX_SEEN_IDS = 10000;
    private const PUBLISH_INTERVAL_MS = 250;
    private const REDIS_TTL_SECONDS = 20;

    /** @var array<string,array<int,array{second:int,buy_notional:float,sell_notional:float,trades:int}>> */
    private array $buckets = [];
    /** @var array<string,array<string,true>> */
    private array $seenIds = [];
    /** @var array<string,list<string>> */
    private array $seenOrder = [];
    /** @var array<string,int> */
    private array $lastPublishedAt = [];
    /** @var array<string,array{event_at_ms:int,received_at_ms:int,last_price:float}> */
    private array $latest = [];
    /** @var array<string,array<string,mixed>> */
    private array $depth = [];

    public function ingestPartialDepth(array $data, int $receivedAtMs): bool
    {
        $symbol = strtoupper(trim((string) ($data['s'] ?? '')));
        $eventAtMs = (int) ($data['E'] ?? $data['T'] ?? 0);
        $bids = is_array($data['b'] ?? null) ? $data['b'] : [];
        $asks = is_array($data['a'] ?? null) ? $data['a'] : [];
        if ($symbol === '' || $eventAtMs <= 0 || $eventAtMs > $receivedAtMs + 5000 || $eventAtMs < $receivedAtMs - 10000 || $bids === [] || $asks === []) return false;
        $sideNotional = static function (array $levels): float {
            $sum = 0.0;
            foreach (array_slice($levels, 0, 5) as $level) {
                if (is_array($level) && isset($level[0], $level[1]) && is_numeric($level[0]) && is_numeric($level[1])) $sum += (float) $level[0] * (float) $level[1];
            }
            return $sum;
        };
        $bidNotional = $sideNotional($bids);
        $askNotional = $sideNotional($asks);
        $bestBid = (float) ($bids[0][0] ?? 0);
        $bestAsk = (float) ($asks[0][0] ?? 0);
        if ($bidNotional <= 0 || $askNotional <= 0 || $bestBid <= 0 || $bestAsk <= 0 || $bestAsk < $bestBid) return false;
        if ($eventAtMs < (int) ($this->depth[$symbol]['event_at_ms'] ?? 0)) return false;
        $total = $bidNotional + $askNotional;
        $mid = ($bestBid + $bestAsk) / 2;
        $this->depth[$symbol] = [
            'kind' => 'partial_depth_top5', 'bid_notional' => $bidNotional, 'ask_notional' => $askNotional,
            'imbalance' => ($bidNotional - $askNotional) / $total,
            'bid_pct' => ($bidNotional / $total) * 100, 'ask_pct' => ($askNotional / $total) * 100,
            'best_bid' => $bestBid, 'best_ask' => $bestAsk, 'spread' => $bestAsk - $bestBid,
            'spread_bps' => $mid > 0 ? (($bestAsk - $bestBid) / $mid) * 10000 : null,
            'event_at_ms' => $eventAtMs, 'received_at_ms' => $receivedAtMs,
            'sequence' => $data['u'] ?? null,
        ];
        return true;
    }

    public function ingestAggregateTrade(array $data, int $receivedAtMs): bool
    {
        $symbol = strtoupper(trim((string) ($data['s'] ?? '')));
        $tradeId = trim((string) ($data['a'] ?? ''));
        $eventAtMs = (int) ($data['T'] ?? $data['E'] ?? 0);
        $price = (float) ($data['p'] ?? 0);
        $quantity = (float) ($data['q'] ?? 0);
        if ($symbol === '' || $tradeId === '' || $eventAtMs <= 0 || $price <= 0 || $quantity <= 0) return false;
        if ($eventAtMs > $receivedAtMs + 5000 || $eventAtMs < $receivedAtMs - (self::MAX_BUCKETS * 1000)) return false;
        if (isset($this->seenIds[$symbol][$tradeId])) return false;

        $this->seenIds[$symbol][$tradeId] = true;
        $this->seenOrder[$symbol][] = $tradeId;
        while (count($this->seenOrder[$symbol]) > self::MAX_SEEN_IDS) {
            $expired = array_shift($this->seenOrder[$symbol]);
            if ($expired !== null) unset($this->seenIds[$symbol][$expired]);
        }

        $second = intdiv($eventAtMs, 1000);
        $bucket = $this->buckets[$symbol][$second] ?? ['second' => $second, 'buy_notional' => 0.0, 'sell_notional' => 0.0, 'trades' => 0];
        $notional = $price * $quantity;
        // Binance m=true: buyer was maker, therefore seller was aggressor.
        if (($data['m'] ?? null) === true) $bucket['sell_notional'] += $notional;
        else $bucket['buy_notional'] += $notional;
        $bucket['trades']++;
        $this->buckets[$symbol][$second] = $bucket;
        ksort($this->buckets[$symbol], SORT_NUMERIC);
        while (count($this->buckets[$symbol]) > self::MAX_BUCKETS) array_shift($this->buckets[$symbol]);

        if ($eventAtMs >= ($this->latest[$symbol]['event_at_ms'] ?? 0)) {
            $this->latest[$symbol] = ['event_at_ms' => $eventAtMs, 'received_at_ms' => $receivedAtMs, 'last_price' => $price];
        }
        if (($receivedAtMs - ($this->lastPublishedAt[$symbol] ?? 0)) >= self::PUBLISH_INTERVAL_MS) $this->publish($symbol, $receivedAtMs);
        return true;
    }

    public function snapshot(string $symbol, string $window, ?int $nowMs = null): ?array
    {
        $symbol = strtoupper(trim($symbol));
        $seconds = self::WINDOWS[$window] ?? null;
        $latest = $this->latest[$symbol] ?? null;
        if ($seconds === null || $latest === null) return null;
        $nowMs ??= (int) floor(microtime(true) * 1000);
        $cutoff = intdiv($nowMs, 1000) - $seconds + 1;
        $rows = array_filter($this->buckets[$symbol] ?? [], static fn (array $row): bool => $row['second'] >= $cutoff);
        $buy = (float) array_sum(array_column($rows, 'buy_notional'));
        $sell = (float) array_sum(array_column($rows, 'sell_notional'));
        $trades = (int) array_sum(array_column($rows, 'trades'));
        $total = $buy + $sell;

        $span = min(10, max(3, intdiv($seconds, 6)));
        $currentCutoff = intdiv($nowMs, 1000) - $span + 1;
        $previousCutoff = $currentCutoff - $span;
        $delta = static function (array $items, int $from, ?int $to = null): float {
            $sum = 0.0;
            foreach ($items as $row) if ($row['second'] >= $from && ($to === null || $row['second'] < $to)) $sum += $row['buy_notional'] - $row['sell_notional'];
            return $sum;
        };
        $currentDelta = $delta($rows, $currentCutoff);
        $previousDelta = $delta($rows, $previousCutoff, $currentCutoff);
        $change = ($currentDelta - $previousDelta) / max(abs($currentDelta), abs($previousDelta), 1.0);
        $momentum = $trades === 0 ? 'unavailable' : ($change > 0.15 ? 'increasing' : ($change < -0.15 ? 'decreasing' : 'stable'));

        $depth = $this->depth[$symbol] ?? null;
        if ($depth !== null && ($nowMs - (int) $depth['event_at_ms']) > 3000) $depth = null;
        return [
            'schema_version' => 1, 'symbol' => $symbol, 'window' => $window,
            'buy_pressure_pct' => $total > 0 ? ($buy / $total) * 100 : null,
            'sell_pressure_pct' => $total > 0 ? ($sell / $total) * 100 : null,
            'buy_notional' => $buy, 'sell_notional' => $sell, 'cvd_notional' => $buy - $sell,
            'trade_count' => $trades, 'momentum' => $momentum, 'last_price' => $latest['last_price'],
            'event_at_ms' => $latest['event_at_ms'], 'received_at_ms' => $latest['received_at_ms'],
            'generated_at_ms' => $nowMs, 'event_age_ms' => max(0, $nowMs - $latest['event_at_ms']),
            'source' => 'binance_usdm_aggtrade', 'definition' => 'executed_aggressive_trade_notional', 'is_prediction' => false,
            'order_book' => $depth,
        ];
    }

    public function read(string $symbol, string $window, ?int $nowMs = null): array
    {
        $symbol = strtoupper(trim($symbol));
        $nowMs ??= (int) floor(microtime(true) * 1000);
        try {
            $raw = Redis::get($this->key($symbol, $window));
        } catch (\Throwable $exception) {
            Log::warning('[MarketPressure] Redis read failed', ['symbol' => $symbol, 'window' => $window, 'error' => $exception->getMessage()]);
            return $this->unavailable($symbol, $window, 'redis_unavailable');
        }
        $snapshot = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($snapshot)) return $this->unavailable($symbol, $window, 'awaiting_server_stream');
        $eventAge = max(0, $nowMs - (int) ($snapshot['event_at_ms'] ?? 0));
        $receivedAge = max(0, $nowMs - (int) ($snapshot['received_at_ms'] ?? 0));
        $maxAge = max(1000, (int) config('market_pressure.stale_after_ms', 3000));
        $snapshot['event_age_ms'] = $eventAge;
        $snapshot['received_age_ms'] = $receivedAge;
        $snapshot['state'] = $eventAge <= $maxAge && $receivedAge <= $maxAge ? 'live' : 'stale';
        $snapshot['usable'] = $snapshot['state'] === 'live' && ($snapshot['trade_count'] ?? 0) > 0;
        if (! $snapshot['usable']) $snapshot['momentum'] = 'unavailable';
        return $snapshot;
    }

    private function publish(string $symbol, int $nowMs): void
    {
        try {
            foreach (array_keys(self::WINDOWS) as $window) {
                $snapshot = $this->snapshot($symbol, $window, $nowMs);
                if ($snapshot !== null) Redis::setex($this->key($symbol, $window), self::REDIS_TTL_SECONDS, json_encode($snapshot, JSON_THROW_ON_ERROR));
            }
            $this->lastPublishedAt[$symbol] = $nowMs;
        } catch (\Throwable $exception) {
            Log::warning('[MarketPressure] Redis publish failed', ['symbol' => $symbol, 'error' => $exception->getMessage()]);
        }
    }

    private function key(string $symbol, string $window): string { return 'autofinx:market:binance_futures:'.$symbol.':pressure:'.$window; }

    private function unavailable(string $symbol, string $window, string $reason): array
    {
        return ['schema_version' => 1, 'symbol' => $symbol, 'window' => $window, 'state' => 'offline', 'usable' => false, 'reason' => $reason,
            'buy_pressure_pct' => null, 'sell_pressure_pct' => null, 'buy_notional' => 0.0, 'sell_notional' => 0.0,
            'cvd_notional' => 0.0, 'trade_count' => 0, 'momentum' => 'unavailable', 'last_price' => null,
            'event_at_ms' => null, 'received_at_ms' => null, 'generated_at_ms' => null, 'event_age_ms' => null,
            'source' => 'binance_usdm_aggtrade', 'definition' => 'executed_aggressive_trade_notional', 'is_prediction' => false];
    }
}
