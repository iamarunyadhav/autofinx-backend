<?php

namespace App\Domain\Exchange;

use App\Support\WebSocket\SimpleWebSocketClient;
use Illuminate\Support\Str;
use RuntimeException;

class BinanceFuturesWebSocketApiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $wsApiUrl,
        private readonly int $timeoutSeconds = 8,
    ) {
    }

    public function placeOrder(array $payload): array
    {
        $params = $payload;
        $params['apiKey'] = $this->apiKey;
        $params['timestamp'] = (int) floor(microtime(true) * 1000);
        $params['recvWindow'] = (int) config('future_trade.binance_rest.recv_window_ms', 15000);
        $params['signature'] = $this->sign($params);

        $request = [
            'id' => (string) Str::uuid(),
            'method' => 'order.place',
            'params' => $params,
        ];

        $client = new SimpleWebSocketClient;
        try {
            $client->connect($this->wsApiUrl, $this->timeoutSeconds);
            $client->sendText(json_encode($request, JSON_UNESCAPED_SLASHES));
            $deadline = time() + $this->timeoutSeconds;

            do {
                $frame = $client->readFrame(1);
                if ($frame === null || ($frame['type'] ?? '') !== 'text') {
                    continue;
                }

                $response = json_decode((string) ($frame['payload'] ?? ''), true);
                if (! is_array($response)) {
                    continue;
                }
                if ((string) ($response['id'] ?? '') !== (string) $request['id']) {
                    continue;
                }

                $status = (int) ($response['status'] ?? 0);
                if ($status < 200 || $status >= 300) {
                    $error = (array) ($response['error'] ?? []);
                    $message = (string) ($error['msg'] ?? $error['message'] ?? 'Binance futures WS order failed.');
                    $code = (int) ($error['code'] ?? $status);
                    throw new RuntimeException($message, $code);
                }

                return (array) ($response['result'] ?? []);
            } while (time() < $deadline);

            throw new RuntimeException('Binance futures WS order timed out waiting for matching response.');
        } finally {
            $client->close();
        }
    }

    private function sign(array $params): string
    {
        unset($params['signature']);
        ksort($params);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return hash_hmac('sha256', $query, $this->apiSecret);
    }
}
