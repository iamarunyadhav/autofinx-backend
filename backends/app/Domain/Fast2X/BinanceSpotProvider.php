<?php

namespace App\Domain\Fast2X;

use App\Domain\Exchange\BinanceSpotClient;
use App\Models\Fast2XConfig;
use App\Support\WebSocket\SimpleWebSocketClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BinanceSpotProvider implements ExchangeProviderInterface
{
    private ?SimpleWebSocketClient $client = null;
    private $callback = null;
    private bool $connected = false;
    private string $feedMode = 'websocket';
    private ?string $lastConnectionError = null;

    public function connectWebSocket(string $symbol, array $settings = []): void
    {
        $stream = strtolower($symbol) . '@ticker';
        $base = rtrim($this->wsBaseUrl($settings), '/');
        $this->client = new SimpleWebSocketClient();
        $this->client->connect($base . '/ws/' . $stream);
        $this->connected = true;
        $this->feedMode = 'websocket';
        $this->lastConnectionError = null;
    }

    public function disconnectWebSocket(): void
    {
        $this->client?->close();
        $this->client = null;
        $this->connected = false;
    }

    public function onPriceUpdate(callable $callback): void
    {
        $this->callback = $callback;
    }

    public function readNextTick(string $symbol, int $timeoutSeconds = 2): ?array
    {
        if (! $this->client) {
            return null;
        }

        $frame = $this->client->readFrame($timeoutSeconds);
        if (($frame['type'] ?? null) !== 'text') {
            return null;
        }

        $payload = json_decode((string) $frame['payload'], true);
        if (! is_array($payload)) {
            return null;
        }

        $price = (float) ($payload['c'] ?? $payload['p'] ?? 0);
        if ($price <= 0) {
            return null;
        }

        $tick = Types::priceTick($symbol, $price, [
            'bid' => isset($payload['b']) ? (float) $payload['b'] : null,
            'ask' => isset($payload['a']) ? (float) $payload['a'] : null,
            'volume' => isset($payload['v']) ? (float) $payload['v'] : null,
            'eventTime' => (int) ($payload['E'] ?? floor(microtime(true) * 1000)),
        ]);

        if ($this->callback) {
            ($this->callback)($tick);
        }

        return $tick;
    }

    public function usePollingFallback(?string $reason = null): void
    {
        $this->disconnectWebSocket();
        $this->feedMode = 'polling';
        $this->lastConnectionError = $reason;
    }

    public function isWebSocketConnected(): bool
    {
        return $this->connected && $this->client !== null;
    }

    public function feedMode(): string
    {
        return $this->feedMode;
    }

    public function lastConnectionError(): ?string
    {
        return $this->lastConnectionError;
    }

    public function latestTicker(string $symbol, array $settings = []): array
    {
        $base = rtrim($this->apiBaseUrl($settings), '/');
        $started = microtime(true);
        $ticker = Http::timeout(5)->get($base . '/api/v3/ticker/24hr', ['symbol' => strtoupper($symbol)]);
        if (! $ticker->successful()) {
            throw new RuntimeException('Binance ticker unavailable.');
        }

        $book = Http::timeout(5)->get($base . '/api/v3/ticker/bookTicker', ['symbol' => strtoupper($symbol)]);
        $bookData = $book->successful() ? $book->json() : [];
        $data = $ticker->json();
        $price = (float) ($data['lastPrice'] ?? 0);

        return [
            'tick' => Types::priceTick($symbol, $price, [
                'bid' => $bookData['bidPrice'] ?? null,
                'ask' => $bookData['askPrice'] ?? null,
                'volume' => $data['volume'] ?? null,
                'eventTime' => (int) ($data['closeTime'] ?? floor(microtime(true) * 1000)),
            ]),
            'change24h' => (float) ($data['priceChangePercent'] ?? 0),
            'latencyMs' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    public function placeMarketBuy(string $symbol, float $amount): array
    {
        return $this->blockedLiveOrder('BUY', $symbol, $amount);
    }

    public function placeMarketSell(string $symbol, float $quantity): array
    {
        return $this->blockedLiveOrder('SELL', $symbol, $quantity);
    }

    public function getBalance(string $asset): array
    {
        return ['asset' => strtoupper($asset), 'free' => 0, 'locked' => 0, 'mode' => 'UNIMPLEMENTED_LIVE'];
    }

    public function getOrderStatus(string $orderId): array
    {
        return ['orderId' => $orderId, 'status' => 'UNKNOWN'];
    }

    public function checkExchangeHealth(array $settings = []): array
    {
        try {
            $base = rtrim($this->apiBaseUrl($settings), '/');
            $response = Http::timeout(5)->get($base . '/api/v3/ping');
            return ['healthy' => $response->successful(), 'status' => $response->successful() ? 'OK' : 'ERROR'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'status' => 'ERROR', 'reason' => $e->getMessage()];
        }
    }

    public function measureLatency(array $settings = []): int
    {
        $started = microtime(true);
        $this->checkExchangeHealth($settings);
        return (int) round((microtime(true) - $started) * 1000);
    }

    public function configuredTicker(Fast2XConfig $config): array
    {
        return $this->latestTicker($config->normalizedSymbol(), $config->mergedSettings());
    }

    public function configuredConnectWebSocket(Fast2XConfig $config): void
    {
        $this->connectWebSocket($config->normalizedSymbol(), $config->mergedSettings());
    }

    public function configuredHealth(Fast2XConfig $config): array
    {
        return $this->checkExchangeHealth($config->mergedSettings());
    }

    public function configuredLatency(Fast2XConfig $config): int
    {
        return $this->measureLatency($config->mergedSettings());
    }

    public function configuredBalance(Fast2XConfig $config, string $asset = 'USDT'): array
    {
        $client = $this->clientFromSettings($config->mergedSettings());
        $balances = $client->fetchBalances();
        $needle = strtoupper($asset);
        $balance = collect($balances)->first(fn (array $row) => strtoupper((string) ($row['asset'] ?? '')) === $needle);

        return [
            'asset' => $needle,
            'free' => isset($balance['free']) ? (float) $balance['free'] : 0.0,
            'locked' => isset($balance['locked']) ? (float) $balance['locked'] : 0.0,
            'mode' => strtoupper($config->executionMode()),
        ];
    }

    public function configuredMarketBuy(Fast2XConfig $config, float $quoteAmount, ?float $referencePrice = null): array
    {
        $settings = $config->mergedSettings();
        $client = $this->clientFromSettings($settings);
        $symbol = $config->normalizedSymbol();
        $market = $this->spotRules($client, $symbol);
        $price = max(0.00000001, $referencePrice ?: $client->getLatestPrice($symbol));
        $rawQty = $quoteAmount / $price;
        $quantity = $this->normalizeQuantity($rawQty, $market['stepSize']);
        $this->assertTradableQuantity($symbol, $quantity, $price, $market);

        $order = $client->placeMarketOrder(
            symbol: $symbol,
            side: 'BUY',
            qty: $quantity,
            clientOrderId: substr('fast2x-buy-' . $config->id . '-' . now()->format('Hisv'), 0, 36),
        );

        return [
            'order' => $order->toArray(),
            'symbol' => $symbol,
            'quantity' => $order->executedQty > 0 ? $order->executedQty : $quantity,
            'price' => $this->averageFillPrice($order->raw, $price),
            'quoteAmount' => $quoteAmount,
            'mode' => strtoupper($config->executionMode()),
        ];
    }

    public function configuredMarketSell(Fast2XConfig $config, float $quantity, ?float $referencePrice = null): array
    {
        $settings = $config->mergedSettings();
        $client = $this->clientFromSettings($settings);
        $symbol = $config->normalizedSymbol();
        $market = $this->spotRules($client, $symbol);
        $price = max(0.00000001, $referencePrice ?: $client->getLatestPrice($symbol));
        $normalizedQty = $this->normalizeQuantity($quantity, $market['stepSize']);
        $this->assertTradableQuantity($symbol, $normalizedQty, $price, $market);

        $order = $client->placeMarketOrder(
            symbol: $symbol,
            side: 'SELL',
            qty: $normalizedQty,
            clientOrderId: substr('fast2x-sell-' . $config->id . '-' . now()->format('Hisv'), 0, 36),
        );

        return [
            'order' => $order->toArray(),
            'symbol' => $symbol,
            'quantity' => $order->executedQty > 0 ? $order->executedQty : $normalizedQty,
            'price' => $this->averageFillPrice($order->raw, $price),
            'mode' => strtoupper($config->executionMode()),
        ];
    }

    private function blockedLiveOrder(string $side, string $symbol, float $amount): array
    {
        if (! (bool) config('fast2x.enable_live_trading')) {
            throw new RuntimeException('Live trading is disabled. Fast2X defaults to paper trading.');
        }

        return [
            'orderId' => 'live-not-implemented',
            'side' => $side,
            'symbol' => strtoupper($symbol),
            'executedQty' => $amount,
            'status' => 'REJECTED',
        ];
    }

    private function clientFromSettings(array $settings): BinanceSpotClient
    {
        $mode = strtolower((string) ($settings['envMode'] ?? 'paper'));
        if (! in_array($mode, ['testnet', 'live'], true)) {
            throw new RuntimeException('Fast2X exchange execution requires Testnet or Live mode in My Config.');
        }

        $useTestnet = $mode === 'testnet';
        $apiKey = (string) ($useTestnet ? ($settings['testnetApiKey'] ?? '') : ($settings['liveApiKey'] ?? ''));
        $apiSecret = (string) ($useTestnet ? ($settings['testnetApiSecret'] ?? '') : ($settings['liveApiSecret'] ?? ''));
        $baseUrl = $this->apiBaseUrl($settings);
        $wsUrl = $this->wsBaseUrl($settings);

        return new BinanceSpotClient($apiKey, $apiSecret, $baseUrl, $wsUrl);
    }

    private function apiBaseUrl(array $settings): string
    {
        $mode = strtolower((string) ($settings['envMode'] ?? 'paper'));
        if ($mode === 'testnet') {
            return (string) ($settings['testnetApiUrl'] ?? config('services.binance.testnet_api_url', 'https://testnet.binance.vision'));
        }

        return (string) ($settings['liveApiUrl'] ?? config('services.binance.api_url', 'https://api.binance.com'));
    }

    private function wsBaseUrl(array $settings): string
    {
        $mode = strtolower((string) ($settings['envMode'] ?? 'paper'));
        if ($mode === 'testnet') {
            return (string) config('services.binance.testnet_ws_url', 'wss://testnet.binance.vision');
        }

        return (string) config('services.binance.ws_url', 'wss://stream.binance.com:9443');
    }

    private function spotRules(BinanceSpotClient $client, string $symbol): array
    {
        $payload = $client->fetchExchangeInfo($symbol);
        $symbolInfo = collect((array) ($payload['symbols'] ?? []))
            ->first(fn (array $row) => strtoupper((string) ($row['symbol'] ?? '')) === strtoupper($symbol));

        if (! is_array($symbolInfo)) {
            throw new RuntimeException("Binance symbol {$symbol} is not available for spot trading.");
        }

        $filters = collect((array) ($symbolInfo['filters'] ?? []))
            ->keyBy(fn (array $row) => (string) ($row['filterType'] ?? ''));
        $lot = (array) $filters->get('LOT_SIZE', []);
        $minNotional = (array) ($filters->get('MIN_NOTIONAL') ?: $filters->get('NOTIONAL') ?: []);

        return [
            'stepSize' => (float) ($lot['stepSize'] ?? 0.000001),
            'minQty' => (float) ($lot['minQty'] ?? 0),
            'maxQty' => (float) ($lot['maxQty'] ?? 0),
            'minNotional' => (float) ($minNotional['minNotional'] ?? 0),
        ];
    }

    private function normalizeQuantity(float $quantity, float $stepSize): float
    {
        if ($stepSize <= 0) {
            return round($quantity, 8);
        }

        $steps = floor(($quantity + 1e-12) / $stepSize);

        return round($steps * $stepSize, 8);
    }

    private function assertTradableQuantity(string $symbol, float $quantity, float $price, array $market): void
    {
        if ($quantity <= 0) {
            throw new RuntimeException("Order quantity became zero after Binance normalization for {$symbol}.");
        }

        if (($market['minQty'] ?? 0) > 0 && $quantity < (float) $market['minQty']) {
            throw new RuntimeException("Order quantity is below Binance minQty for {$symbol}.");
        }

        if (($market['maxQty'] ?? 0) > 0 && $quantity > (float) $market['maxQty']) {
            throw new RuntimeException("Order quantity exceeds Binance maxQty for {$symbol}.");
        }

        if (($market['minNotional'] ?? 0) > 0 && ($quantity * $price) < (float) $market['minNotional']) {
            throw new RuntimeException("Order notional is below Binance minNotional for {$symbol}.");
        }
    }

    private function averageFillPrice(array $raw, float $fallbackPrice): float
    {
        $fills = (array) ($raw['fills'] ?? []);
        if ($fills !== []) {
            $quote = 0.0;
            $qty = 0.0;
            foreach ($fills as $fill) {
                $fillQty = (float) ($fill['qty'] ?? 0);
                $fillPrice = (float) ($fill['price'] ?? 0);
                $qty += $fillQty;
                $quote += $fillQty * $fillPrice;
            }

            if ($qty > 0 && $quote > 0) {
                return round($quote / $qty, 8);
            }
        }

        $executedQty = (float) ($raw['executedQty'] ?? 0);
        $cummulativeQuoteQty = (float) ($raw['cummulativeQuoteQty'] ?? 0);
        if ($executedQty > 0 && $cummulativeQuoteQty > 0) {
            return round($cummulativeQuoteQty / $executedQty, 8);
        }

        return $fallbackPrice;
    }
}
