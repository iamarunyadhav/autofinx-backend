<?php

namespace App\Console\Commands;

use App\Domain\Market\RealtimeMarketFeedService;
use App\Support\WebSocket\SimpleWebSocketClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MarketWsConsumerCommand extends Command
{
    protected $signature = 'market:ws-consume
        {--exchange=binance}
        {--market-type=spot : spot|futures}
        {--workspace= : Limit active-bot symbol discovery to one workspace}
        {--bot= : Limit stream discovery to one ratio bot config id}
        {--symbols= : Comma-separated symbols; defaults to active ratio bot symbols}
        {--streams=trade,kline_1m,miniTicker : Binance stream suffixes}
        {--sleep=1}
        {--max-runtime=0 : Max runtime in seconds (0 = infinite)}
        {--reconnect-sleep=3 : Reconnect delay in seconds}
        {--testnet : Use Binance testnet websocket base when available}
        {--once}
        {--store-ohlc}
        {--ohlc-intervals=1m : Comma-separated OHLC intervals to persist during --once polling fallback}
        {--no-dispatch : Do not dispatch ratio bot evaluations on feed updates}';

    protected $description = 'Consume Binance market websocket streams, with REST polling fallback for --once runs.';

    public function handle(RealtimeMarketFeedService $feeds): int
    {
        $marketType = strtolower((string) $this->option('market-type'));
        if (! in_array($marketType, ['spot', 'futures'], true)) {
            $this->error('market-type must be either spot or futures.');

            return self::FAILURE;
        }

        $symbols = $this->resolveSymbols($feeds, $marketType);
        if ($symbols === []) {
            $this->warn('No symbols resolved for market feed consumption.');

            return self::SUCCESS;
        }

        $dispatchEvaluations = ! (bool) $this->option('no-dispatch');
        if ((bool) $this->option('once')) {
            return $this->pollOnce($feeds, $symbols, $marketType, $dispatchEvaluations);
        }

        $streams = array_values(array_filter(array_map(
            static fn (string $stream) => trim($stream),
            explode(',', (string) $this->option('streams'))
        )));
        $maxRuntime = max(0, (int) $this->option('max-runtime'));
        $reconnectSleep = max(1, (int) $this->option('reconnect-sleep'));
        $startedAt = time();
        $testnet = (bool) $this->option('testnet');
        $client = new SimpleWebSocketClient();

        $this->info(sprintf(
            'Starting realtime market feed consumer market_type=%s symbols=%s',
            $marketType,
            implode(',', $symbols)
        ));

        try {
            while (true) {
                if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                    $this->info('Max runtime reached, stopping market feed consumer.');
                    break;
                }

                try {
                    $wsUrl = $feeds->buildStreamUrl($symbols, $marketType, $streams, $testnet);
                    $client->connect($wsUrl);
                    $this->line('Connected to Binance market websocket.');

                    while (true) {
                        if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                            $client->close();
                            break 2;
                        }

                        $frame = $client->readFrame(1);
                        if ($frame === null) {
                            continue;
                        }
                        if ($frame['type'] === 'close') {
                            throw new RuntimeException('Remote websocket closed the market stream.');
                        }
                        if ($frame['type'] !== 'text') {
                            continue;
                        }

                        $payload = json_decode((string) $frame['payload'], true);
                        if (! is_array($payload)) {
                            continue;
                        }

                        $feeds->ingest($payload, $marketType, $dispatchEvaluations);
                    }
                } catch (\Throwable $e) {
                    $feeds->markDisconnected($symbols, $marketType, $e->getMessage());
                    $this->warn('Market websocket error: ' . $e->getMessage());
                } finally {
                    $client->close();
                }

                sleep($reconnectSleep);
            }
        } finally {
            $client->close();
        }

        return self::SUCCESS;
    }

    /**
     * @param  string[]  $symbols
     */
    private function pollOnce(
        RealtimeMarketFeedService $feeds,
        array $symbols,
        string $marketType,
        bool $dispatchEvaluations,
    ): int {
        $apiBase = $this->apiBaseUrl($marketType);
        $sleepSeconds = max(1, (int) $this->option('sleep'));
        $storeOhlc = (bool) $this->option('store-ohlc');

        do {
            foreach ($symbols as $symbol) {
                try {
                    $ticker = Http::timeout(10)->get($apiBase . $this->tickerPath($marketType), ['symbol' => $symbol]);
                    if ($ticker->failed()) {
                        $this->warn("Ticker fetch failed for {$symbol}: {$ticker->body()}");
                        continue;
                    }

                    $price = (float) ($ticker->json('price') ?? $ticker->json('lastPrice') ?? 0);
                    if ($price <= 0) {
                        continue;
                    }

                    $eventTime = (int) floor(microtime(true) * 1000);
                    $feeds->ingest([
                        'e' => '24hrMiniTicker',
                        's' => $symbol,
                        'c' => (string) $price,
                        'E' => $eventTime,
                    ], $marketType, $dispatchEvaluations);

                    if ($storeOhlc) {
                        $this->persistLatestCandles($feeds, $apiBase, $marketType, $symbol, $dispatchEvaluations);
                    }
                } catch (\Throwable $e) {
                    $this->warn("Feed refresh failed for {$symbol}: {$e->getMessage()}");
                }
            }

            if ((bool) $this->option('once')) {
                break;
            }

            sleep($sleepSeconds);
        } while (true);

        return self::SUCCESS;
    }

    private function persistLatestCandles(
        RealtimeMarketFeedService $feeds,
        string $apiBase,
        string $marketType,
        string $symbol,
        bool $dispatchEvaluations,
    ): void {
        $intervals = array_values(array_filter(array_map(
            static fn (string $interval) => trim(strtolower($interval)),
            explode(',', (string) $this->option('ohlc-intervals'))
        )));

        foreach ($intervals as $interval) {
            $klines = Http::timeout(10)->get($apiBase . $this->klinePath($marketType), [
                'symbol' => $symbol,
                'interval' => $interval,
                'limit' => 1,
            ]);
            if ($klines->failed()) {
                continue;
            }

            $row = $klines->json('0');
            if (! is_array($row) || count($row) < 6) {
                continue;
            }

            $feeds->ingest([
                'e' => 'kline',
                's' => $symbol,
                'E' => (int) ($row[6] ?? floor(microtime(true) * 1000)),
                'k' => [
                    't' => (int) $row[0],
                    'T' => (int) ($row[6] ?? $row[0]),
                    'i' => $interval,
                    'o' => (string) $row[1],
                    'h' => (string) $row[2],
                    'l' => (string) $row[3],
                    'c' => (string) $row[4],
                    'v' => (string) $row[5],
                    'x' => true,
                ],
            ], $marketType, $dispatchEvaluations);
        }
    }

    /**
     * @return string[]
     */
    private function resolveSymbols(RealtimeMarketFeedService $feeds, string $marketType): array
    {
        $symbolsOption = trim((string) $this->option('symbols'));
        if ($symbolsOption !== '') {
            return array_values(array_filter(array_map(
                static fn (string $symbol) => strtoupper(trim($symbol)),
                explode(',', $symbolsOption)
            )));
        }

        $botId = trim((string) $this->option('bot'));
        $workspaceId = trim((string) $this->option('workspace'));

        $resolved = $feeds->selectedSymbols($marketType, $workspaceId !== '' ? $workspaceId : null, $botId !== '' ? $botId : null);
        if ($resolved !== []) {
            return $resolved;
        }

        return $marketType === 'futures' ? ['BTCUSDT'] : ['BTCUSDT', 'ETHUSDT'];
    }

    private function apiBaseUrl(string $marketType): string
    {
        if ($marketType === 'futures') {
            return rtrim((string) config('services.binance.futures_api_url', 'https://fapi.binance.com'), '/');
        }

        return rtrim((string) config('services.binance.api_url', 'https://api.binance.com'), '/');
    }

    private function tickerPath(string $marketType): string
    {
        return $marketType === 'futures'
            ? '/fapi/v1/ticker/price'
            : '/api/v3/ticker/price';
    }

    private function klinePath(string $marketType): string
    {
        return $marketType === 'futures'
            ? '/fapi/v1/klines'
            : '/api/v3/klines';
    }
}
