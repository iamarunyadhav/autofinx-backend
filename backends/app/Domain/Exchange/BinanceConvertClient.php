<?php

namespace App\Domain\Exchange;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BinanceConvertClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $baseUrl,
    ) {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new RuntimeException('Binance API credentials are required for Convert execution.');
        }
    }

    public function getQuote(string $fromAsset, string $toAsset, float $fromAmount, string $validTime = '10s'): array
    {
        if ($fromAmount <= 0) {
            throw new RuntimeException('Convert quote requires a positive fromAmount.');
        }

        $response = $this->signedRequest('POST', '/sapi/v1/convert/getQuote', [
            'fromAsset' => strtoupper($fromAsset),
            'toAsset' => strtoupper($toAsset),
            'fromAmount' => $this->formatDecimal($fromAmount),
            'walletType' => 'SPOT',
            'validTime' => $validTime,
        ]);

        $quote = (array) $response->json();
        if ((string) ($quote['quoteId'] ?? '') === '') {
            throw new RuntimeException('Binance Convert quote did not include quoteId.');
        }

        return $quote;
    }

    public function acceptQuote(string $quoteId): array
    {
        $response = $this->signedRequest('POST', '/sapi/v1/convert/acceptQuote', [
            'quoteId' => $quoteId,
        ]);

        return (array) $response->json();
    }

    public function orderStatus(?string $orderId = null, ?string $quoteId = null): array
    {
        $params = [];
        if ($orderId) {
            $params['orderId'] = $orderId;
        }
        if ($quoteId) {
            $params['quoteId'] = $quoteId;
        }
        if ($params === []) {
            throw new RuntimeException('Convert order status requires orderId or quoteId.');
        }

        $response = $this->signedRequest('GET', '/sapi/v1/convert/orderStatus', $params);

        return (array) $response->json();
    }

    private function signedRequest(string $method, string $path, array $params = []): Response
    {
        $signedPayload = $this->sign($params);
        $response = $this->dispatch($method, $path, $signedPayload);

        if ($this->isTimestampOutsideRecvWindow($response)) {
            $this->syncServerTimeOffset();
            $signedPayload = $this->sign($params);
            $response = $this->dispatch($method, $path, $signedPayload);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Binance Convert request failed. ' . $response->body());
        }

        return $response;
    }

    private function dispatch(string $method, string $path, array $signedPayload): Response
    {
        return strtoupper($method) === 'GET'
            ? Http::withHeaders($this->apiHeaders())->get($this->url($path), $signedPayload)
            : Http::withHeaders($this->apiHeaders())->asForm()->post($this->url($path), $signedPayload);
    }

    private function apiHeaders(): array
    {
        return ['X-MBX-APIKEY' => $this->apiKey];
    }

    private function sign(array $payload): array
    {
        $payload['timestamp'] = (int) floor(microtime(true) * 1000) + $this->serverTimeOffsetMs();
        $payload['recvWindow'] = max(5000, min(60000, (int) config('services.binance.spot_recv_window_ms', 5000)));
        ksort($payload);

        $query = http_build_query($payload, '', '&');
        $payload['signature'] = hash_hmac('sha256', $query, $this->apiSecret);

        return $payload;
    }

    private function isTimestampOutsideRecvWindow(Response $response): bool
    {
        return (int) ($response->json('code') ?? 0) === -1021
            || str_contains(strtolower($response->body()), 'outside of the recvwindow');
    }

    private function serverTimeOffsetKey(): string
    {
        return 'autofinx:binance:convert_time_offset:' . md5($this->baseUrl);
    }

    private function serverTimeOffsetMs(): int
    {
        return (int) Cache::get($this->serverTimeOffsetKey(), 0);
    }

    private function syncServerTimeOffset(): void
    {
        try {
            $started = (int) floor(microtime(true) * 1000);
            $response = Http::get($this->url('/api/v3/time'));
            $finished = (int) floor(microtime(true) * 1000);
            $serverTime = (int) ($response->json('serverTime') ?? 0);
            if ($response->successful() && $serverTime > 0) {
                Cache::put($this->serverTimeOffsetKey(), $serverTime - (int) floor(($started + $finished) / 2), 600);
            }
        } catch (Throwable) {
            Cache::forget($this->serverTimeOffsetKey());
        }
    }

    private function formatDecimal(float $value): string
    {
        $raw = number_format($value, 8, '.', '');
        $trimmed = rtrim(rtrim($raw, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }
}
