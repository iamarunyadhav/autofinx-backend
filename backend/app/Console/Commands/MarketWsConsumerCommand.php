<?php

namespace App\Console\Commands;

use App\Domain\Market\MarketPriceCacheService;
use App\Models\MarketOhlc;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MarketWsConsumerCommand extends Command
{
    protected $signature = 'market:ws-consume
        {--exchange=binance}
        {--symbols=BTCUSDT,ETHUSDT}
        {--sleep=1}
        {--once}
        {--store-ohlc}';

    protected $description = 'Consume market feed and cache latest prices in Redis (REST fallback worker).';

    public function handle(MarketPriceCacheService $cache): int
    {
        $exchange = strtolower((string) $this->option('exchange'));
        $symbols = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('symbols')))));
        $sleepSeconds = max(1, (int) $this->option('sleep'));
        $once = (bool) $this->option('once');
        $storeOhlc = (bool) $this->option('store-ohlc');
        $apiBase = rtrim((string) config('services.binance.api_url'), '/');

        do {
            foreach ($symbols as $symbol) {
                $symbol = strtoupper($symbol);
                try {
                    $ticker = Http::get("{$apiBase}/api/v3/ticker/price", ['symbol' => $symbol]);
                    if ($ticker->failed()) {
                        $this->warn("Ticker fetch failed for {$symbol}: {$ticker->body()}");
                        continue;
                    }

                    $price = (float) ($ticker->json('price') ?? 0);
                    if ($price <= 0) {
                        continue;
                    }

                    $cache->setLatestPrice($exchange, $symbol, $price);

                    if ($storeOhlc) {
                        $this->persistLatestMinuteCandle($apiBase, $exchange, $symbol);
                    }
                } catch (\Throwable $e) {
                    $this->warn("Feed update failed for {$symbol}: {$e->getMessage()}");
                }
            }

            if ($once) {
                break;
            }

            sleep($sleepSeconds);
        } while (true);

        return self::SUCCESS;
    }

    private function persistLatestMinuteCandle(string $apiBase, string $exchange, string $symbol): void
    {
        $klines = Http::get("{$apiBase}/api/v3/klines", [
            'symbol' => $symbol,
            'interval' => '1m',
            'limit' => 1,
        ]);
        if ($klines->failed()) {
            return;
        }

        $row = $klines->json('0');
        if (! is_array($row) || count($row) < 6) {
            return;
        }

        $openTs = (int) $row[0];
        $ts = now()->setTimestamp((int) floor($openTs / 1000));

        $exists = MarketOhlc::where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('timeframe', '1m')
            ->where('ts', $ts)
            ->exists();

        if ($exists) {
            return;
        }

        MarketOhlc::create([
            'id' => (string) Str::uuid(),
            'exchange' => $exchange,
            'symbol' => $symbol,
            'timeframe' => '1m',
            'open' => (float) $row[1],
            'high' => (float) $row[2],
            'low' => (float) $row[3],
            'close' => (float) $row[4],
            'volume' => (float) $row[5],
            'ts' => $ts,
        ]);
    }
}
