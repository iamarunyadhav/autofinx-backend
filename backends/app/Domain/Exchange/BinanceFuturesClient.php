<?php

namespace App\Domain\Exchange;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BinanceFuturesClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $baseUrl,
    ) {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new RuntimeException('Binance futures API credentials are required.');
        }
    }

    public function ping(): array
    {
        $response = Http::get($this->url('/fapi/v1/ping'));
        $this->assertSuccess($response, 'Failed to ping Binance futures.');

        return (array) $response->json();
    }

    public function fetchExchangeInfo(?string $symbol = null): array
    {
        $params = [];
        if ($symbol) {
            $params['symbol'] = BinanceSymbolNormalizer::normalize($symbol);
        }

        $response = Http::get($this->url('/fapi/v1/exchangeInfo'), $params);
        $this->assertSuccess($response, 'Failed to fetch Binance futures exchange info.');

        return (array) $response->json();
    }

    public function fetchMarkPrice(string $symbol): array
    {
        $response = Http::get($this->url('/fapi/v1/premiumIndex'), [
            'symbol' => BinanceSymbolNormalizer::normalize($symbol),
        ]);
        $this->assertSuccess($response, 'Failed to fetch Binance futures mark price.');

        return (array) $response->json();
    }

    public function fetchAccountInfo(): array
    {
        $response = $this->signedRequest('GET', '/fapi/v2/account');

        return (array) $response->json();
    }

    public function fetchPositionMode(): array
    {
        $response = $this->signedRequest('GET', '/fapi/v1/positionSide/dual');

        return (array) $response->json();
    }

    public function fetchPositionRisk(?string $symbol = null): array
    {
        $params = [];
        if ($symbol) {
            $params['symbol'] = BinanceSymbolNormalizer::normalize($symbol);
        }

        $response = $this->signedRequest('GET', '/fapi/v2/positionRisk', $params);

        return (array) $response->json();
    }

    public function fetchOpenOrders(?string $symbol = null): array
    {
        $params = [];
        if ($symbol) {
            $params['symbol'] = BinanceSymbolNormalizer::normalize($symbol);
        }

        $response = $this->signedRequest('GET', '/fapi/v1/openOrders', $params);

        return (array) $response->json();
    }

    public function createListenKey(): string
    {
        $response = $this->keyedRequest('POST', '/fapi/v1/listenKey');
        $listenKey = (string) ($response->json('listenKey') ?? '');
        if ($listenKey === '') {
            throw new RuntimeException('Failed to create Binance futures listenKey.');
        }

        return $listenKey;
    }

    public function keepAliveListenKey(string $listenKey): void
    {
        $this->keyedRequest('PUT', '/fapi/v1/listenKey', ['listenKey' => $listenKey]);
    }

    public function closeListenKey(string $listenKey): void
    {
        $this->keyedRequest('DELETE', '/fapi/v1/listenKey', ['listenKey' => $listenKey], false);
    }

    public function setLeverage(string $symbol, int|float $leverage): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/leverage', [
            'symbol' => BinanceSymbolNormalizer::normalize($symbol),
            'leverage' => (int) round($leverage),
        ]);

        return (array) $response->json();
    }

    public function setMarginType(string $symbol, string $marginMode): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/marginType', [
            'symbol' => BinanceSymbolNormalizer::normalize($symbol),
            'marginType' => strtoupper($marginMode),
        ], false);

        if ($response->successful()) {
            return (array) $response->json();
        }

        $body = (string) $response->body();
        if (str_contains($body, '-4046') || str_contains(strtolower($body), 'no need to change')) {
            return ['message' => 'margin_type_already_set'];
        }

        $this->assertSuccess($response, 'Failed to set Binance futures margin type.');

        return (array) $response->json();
    }

    public function placeOrder(array $payload): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', $payload);

        return (array) $response->json();
    }

    public function fetchOrder(string $symbol, ?string $orderId = null, ?string $clientOrderId = null): array
    {
        $params = ['symbol' => BinanceSymbolNormalizer::normalize($symbol)];
        if ($orderId) {
            $params['orderId'] = $orderId;
        }
        if ($clientOrderId) {
            $params['origClientOrderId'] = $clientOrderId;
        }

        $response = $this->signedRequest('GET', '/fapi/v1/order', $params);

        return (array) $response->json();
    }

    private function signedRequest(string $method, string $path, array $params = [], bool $throw = true): Response
    {
        $signedPayload = $this->sign($params);

        $response = match (strtoupper($method)) {
            'GET' => Http::withHeaders($this->apiHeaders())->get($this->url($path), $signedPayload),
            'DELETE' => Http::withHeaders($this->apiHeaders())->delete($this->url($path), $signedPayload),
            default => Http::withHeaders($this->apiHeaders())->asForm()->post($this->url($path), $signedPayload),
        };

        if ($throw) {
            $this->assertSuccess($response, 'Binance futures signed request failed.');
        }

        return $response;
    }

    private function keyedRequest(string $method, string $path, array $params = [], bool $throw = true): Response
    {
        $response = match (strtoupper($method)) {
            'GET' => Http::withHeaders($this->apiHeaders())->get($this->url($path), $params),
            'PUT' => Http::withHeaders($this->apiHeaders())->asForm()->put($this->url($path), $params),
            'DELETE' => Http::withHeaders($this->apiHeaders())->delete($this->url($path), $params),
            default => Http::withHeaders($this->apiHeaders())->asForm()->post($this->url($path), $params),
        };

        if ($throw) {
            $this->assertSuccess($response, 'Binance futures keyed request failed.');
        }

        return $response;
    }

    private function apiHeaders(): array
    {
        return ['X-MBX-APIKEY' => $this->apiKey];
    }

    private function sign(array $payload): array
    {
        $payload['timestamp'] = (int) floor(microtime(true) * 1000);
        $payload['recvWindow'] = 5000;
        ksort($payload);

        $query = http_build_query($payload, '', '&');
        $payload['signature'] = hash_hmac('sha256', $query, $this->apiSecret);

        return $payload;
    }

    private function assertSuccess(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException($message . ' ' . $response->body());
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }
}
