<?php

namespace App\Domain\Market;

use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\Symbol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RealtimeMarketFeedService
{
    public function __construct(
        private readonly MarketPriceCacheService $prices,
    ) {
    }

    /**
     * @return string[]
     */
    public function selectedSymbols(string $marketType = 'spot', ?string $workspaceId = null, ?string $botId = null): array
    {
        return RatioBotConfig::query()
            ->when($botId, fn ($query) => $query->whereKey($botId))
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where('market_type', strtolower($marketType))
            ->where('enabled', true)
            ->orderBy('symbol')
            ->pluck('symbol')
            ->map(fn (string $symbol) => strtoupper(trim($symbol)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  string[]  $symbols
     * @param  string[]  $streams
     */
    public function buildStreamUrl(array $symbols, string $marketType = 'spot', array $streams = ['trade', 'kline_1m', 'miniTicker'], bool $testnet = false): string
    {
        $symbols = array_values(array_filter(array_map(
            static fn (string $symbol) => strtolower(trim($symbol)),
            $symbols
        )));

        if ($symbols === []) {
            throw new \RuntimeException('At least one symbol is required to build a market stream URL.');
        }

        $normalizedStreams = array_values(array_filter(array_map(
            static fn (string $stream) => trim($stream),
            $streams
        )));

        $streamParts = [];
        foreach ($symbols as $symbol) {
            foreach ($normalizedStreams as $stream) {
                $streamParts[] = "{$symbol}@{$stream}";
            }
        }

        $baseUrl = $this->marketTypeBaseUrl($marketType, $testnet);

        return rtrim($baseUrl, '/') . '/stream?streams=' . implode('/', $streamParts);
    }

    public function ingest(array $payload, string $marketType = 'spot', bool $dispatchEvaluations = true): void
    {
        $data = is_array($payload['data'] ?? null) ? (array) $payload['data'] : $payload;
        $eventType = strtolower((string) ($data['e'] ?? ''));
        $stream = strtolower((string) ($payload['stream'] ?? ''));

        $symbol = strtoupper((string) ($data['s'] ?? $data['symbol'] ?? ''));
        if ($symbol === '') {
            return;
        }

        $exchangeKey = $this->exchangeKey($marketType);
        $eventTimestampMs = (int) ($data['E'] ?? $data['T'] ?? $data['t'] ?? floor(microtime(true) * 1000));
        $eventLagMs = max(0, (int) floor(microtime(true) * 1000) - $eventTimestampMs);
        $price = $this->extractPrice($eventType, $data);
        $closedKline = false;
        $timeframe = '1m';

        if ($price !== null && $price > 0) {
            $this->prices->setLatestPrice($exchangeKey, $symbol, $price, $eventTimestampMs);
        }

        if ($eventType === 'kline' && is_array($data['k'] ?? null)) {
            $kline = (array) $data['k'];
            $closedKline = (bool) ($kline['x'] ?? false);
            $timeframe = strtolower((string) ($kline['i'] ?? '1m'));
            $openTs = Carbon::createFromTimestampMs((int) ($kline['t'] ?? $eventTimestampMs));

            MarketOhlc::updateOrCreate(
                [
                    'exchange' => $exchangeKey,
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'ts' => $openTs,
                ],
                [
                    'id' => MarketOhlc::query()
                        ->where('exchange', $exchangeKey)
                        ->where('symbol', $symbol)
                        ->where('timeframe', $timeframe)
                        ->where('ts', $openTs)
                        ->value('id') ?: (string) Str::uuid(),
                    'open' => (float) ($kline['o'] ?? 0),
                    'high' => (float) ($kline['h'] ?? 0),
                    'low' => (float) ($kline['l'] ?? 0),
                    'close' => (float) ($kline['c'] ?? 0),
                    'volume' => (float) ($kline['v'] ?? 0),
                ]
            );

            if ((float) ($kline['c'] ?? 0) > 0) {
                $price = (float) $kline['c'];
                $this->prices->setLatestPrice($exchangeKey, $symbol, $price, (int) ($kline['T'] ?? $eventTimestampMs));
            }
        }

        $botConfigs = RatioBotConfig::query()
            ->with('state')
            ->where('symbol', $symbol)
            ->where('market_type', strtolower($marketType))
            ->get();

        foreach ($botConfigs as $config) {
            $this->touchFeedStatus(
                $config,
                $price,
                $eventTimestampMs,
                $stream !== '' ? $stream : ($eventType !== '' ? $eventType : 'websocket'),
                'healthy',
                ['event_type' => $eventType, 'stream' => $stream, 'closed_kline' => $closedKline, 'event_lag_ms' => $eventLagMs]
            );

            if ($closedKline) {
                $config->state?->update(['last_candle_close_at' => Carbon::createFromTimestampMs($eventTimestampMs)]);
            }

            if (! $dispatchEvaluations || ! $config->enabled) {
                continue;
            }

            if ($closedKline && ! $config->evaluatesOnCandleClose()) {
                continue;
            }

            if (! $closedKline && ! $config->evaluatesOnTick()) {
                continue;
            }

            $debounceSeconds = max(1, (int) ($config->min_seconds_between_evaluations ?? 1));
            $cacheKey = sprintf(
                'ratio-bot:feed-dispatch:%s:%s:%s',
                $config->id,
                $closedKline ? 'candle' : ($eventType !== '' ? $eventType : 'tick'),
                $closedKline ? Carbon::createFromTimestampMs($eventTimestampMs)->format('YmdHis') : floor($eventTimestampMs / max($debounceSeconds * 1000, 1))
            );

            if (! Cache::add($cacheKey, 1, now()->addSeconds($debounceSeconds))) {
                continue;
            }

            RunRatioBotEvaluationJob::dispatch((string) $config->id, false);
        }
    }

    /**
     * @param  string[]  $symbols
     */
    public function markDisconnected(array $symbols, string $marketType, string $reason): void
    {
        $symbolList = array_values(array_filter(array_map(
            static fn (string $symbol) => strtoupper(trim($symbol)),
            $symbols
        )));

        if ($symbolList === []) {
            return;
        }

        $configs = RatioBotConfig::query()
            ->with('state')
            ->whereIn('symbol', $symbolList)
            ->where('market_type', strtolower($marketType))
            ->get();

        foreach ($configs as $config) {
            $this->touchFeedStatus($config, null, null, 'websocket', 'degraded', ['reason' => $reason]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function touchFeedStatus(
        RatioBotConfig $config,
        ?float $price,
        ?int $timestampMs,
        string $source,
        string $status,
        array $context = [],
    ): void {
        $symbolModel = Symbol::firstOrCreate(
            ['symbol' => $config->normalizedSymbol()],
            [
                'id' => (string) Str::uuid(),
                'type' => 'crypto',
                'exchange' => $config->exchangeKey(),
                'metadata' => ['auto_created' => true],
            ]
        );

        $tickAt = $timestampMs ? Carbon::createFromTimestampMs($timestampMs) : now();
        $previousStatus = (string) ($config->state?->feed_status ?? '');

        MarketFeedStatus::updateOrCreate(
            [
                'workspace_id' => $config->workspace_id,
                'symbol_id' => $symbolModel->id,
                'exchange' => $config->exchangeKey(),
                'market_type' => $config->marketType(),
            ],
            [
                'id' => MarketFeedStatus::query()
                    ->where('workspace_id', $config->workspace_id)
                    ->where('symbol_id', $symbolModel->id)
                    ->where('exchange', $config->exchangeKey())
                    ->where('market_type', $config->marketType())
                    ->value('id') ?: (string) Str::uuid(),
                'symbol' => $config->normalizedSymbol(),
                'last_tick_at' => $tickAt,
                'last_price' => $price,
                'source' => $source,
                'status' => $status,
                'meta_json' => $context,
            ]
        );

        if ($config->state) {
            $config->state->update([
                'last_seen_price' => $price ?? $config->state->last_seen_price,
                'last_price_source' => $source,
                'last_price_timestamp' => $tickAt,
                'feed_status' => $status,
                'feed_status_updated_at' => now(),
                'runtime_meta' => array_merge((array) ($config->state->runtime_meta ?? []), [
                    'last_feed_event' => [
                        'source' => $source,
                        'status' => $status,
                        'context' => $context,
                    ],
                ]),
            ]);
        }

        if ($previousStatus !== $status) {
            RatioBotEvent::create([
                'ratio_bot_config_id' => $config->id,
                'event_type' => RatioBotEvent::EVENT_FEED_HEALTH_CHANGED,
                'context' => [
                    'previous_status' => $previousStatus !== '' ? $previousStatus : null,
                    'status' => $status,
                    'source' => $source,
                    'price' => $price,
                ] + $context,
                'occurred_at' => now(),
            ]);
        }
    }

    private function extractPrice(string $eventType, array $data): ?float
    {
        return match ($eventType) {
            'trade', 'aggtrade' => isset($data['p']) ? (float) $data['p'] : null,
            '24hrminiticker' => isset($data['c']) ? (float) $data['c'] : null,
            'kline' => isset($data['k']['c']) ? (float) $data['k']['c'] : null,
            default => isset($data['price']) ? (float) $data['price'] : null,
        };
    }

    private function exchangeKey(string $marketType): string
    {
        return strtolower($marketType) === 'futures' ? 'binance_futures' : 'binance';
    }

    private function marketTypeBaseUrl(string $marketType, bool $testnet): string
    {
        if (strtolower($marketType) === 'futures') {
            return $testnet
                ? (string) config('services.binance.futures_testnet_ws_url', 'wss://stream.binancefuture.com')
                : (string) config('services.binance.futures_ws_url', 'wss://fstream.binance.com');
        }

        return $testnet
            ? (string) config('services.binance.testnet_ws_url', 'wss://testnet.binance.vision')
            : (string) config('services.binance.ws_url', 'wss://stream.binance.com:9443');
    }
}
