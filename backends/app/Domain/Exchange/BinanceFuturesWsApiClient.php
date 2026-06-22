<?php

namespace App\Domain\Exchange;

use App\Support\WebSocket\SimpleWebSocketClient;
use Illuminate\Support\Str;
use RuntimeException;

class BinanceFuturesWsApiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $wsUrl,
        private readonly ?SimpleWebSocketClient $socket = null,
    ) {
        if (trim($this->apiKey) === '' || trim($this->apiSecret) === '') {
            throw new RuntimeException('Binance futures WS API credentials are required.');
        }
    }

    public function placeOrder(array $payload, int $timeoutSeconds = 8): array
    {
        $params = $this->signedParams($payload);
        $requestId = (string) ($payload['id'] ?? Str::uuid());
        $request = [
            'id' => $requestId,
            'method' => 'order.place',
            'params' => $params,
        ];

        $client = $this->socket ?? new SimpleWebSocketClient;

        try {
            $client->connect($this->wsUrl, $timeoutSeconds);
            $client->sendText(json_encode($request, JSON_UNESCAPED_SLASHES));

            $deadline = time() + $timeoutSeconds;
            while (time() <= $deadline) {
                $frame = $client->readFrame(1);
                if ($frame === null || ($frame['type'] ?? '') !== 'text') {
                    continue;
                }

                $decoded = json_decode((string) ($frame['payload'] ?? ''), true);
                if (! is_array($decoded)) {
                    continue;
                }
                if ((string) ($decoded['id'] ?? '') !== $requestId) {
                    continue;
                }

                if ((int) ($decoded['status'] ?? 0) !== 200) {
                    $code = (string) data_get($decoded, 'error.code', 'ws_order_failed');
                    $message = (string) data_get($decoded, 'error.msg', 'Binance futures WS order failed.');
                    throw new RuntimeException("Binance futures WS order failed ({$code}): {$message}");
                }

                return [
                    ...(array) ($decoded['result'] ?? []),
                    '_ws_api' => [
                        'id' => $requestId,
                        'status' => $decoded['status'] ?? null,
                        'rateLimits' => $decoded['rateLimits'] ?? [],
                    ],
                ];
            }
        } finally {
            $client->close();
        }

        throw new RuntimeException('Binance futures WS order timed out before acknowledgement.');
    }

    private function signedParams(array $payload): array
    {
        $params = $payload;
        unset($params['id']);
        $params['apiKey'] = $this->apiKey;
        $params['timestamp'] = (int) floor(microtime(true) * 1000);
        $params['recvWindow'] = (int) ($params['recvWindow'] ?? config('future_trade.binance_rest.recv_window_ms', 15000));

        ksort($params);
        $query = collect($params)
            ->map(fn ($value, $key) => $key.'='.$value)
            ->implode('&');
        $params['signature'] = hash_hmac('sha256', $query, $this->apiSecret);

        return $params;
    }
}
