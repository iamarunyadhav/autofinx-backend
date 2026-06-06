<?php

namespace App\Domain\Exchange;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class BinanceRestCircuitBreaker
{
    public const BAN_UNTIL_KEY = 'autofinx:binance:rest:ban_until';

    public const LAST_ERROR_KEY = 'autofinx:binance:rest:last_error';

    public const LAST_OK_AT_KEY = 'autofinx:binance:rest:last_ok_at';

    private const LAST_CALL_AT_KEY = 'autofinx:binance:rest:last_call_at_ms';

    public function assertAvailable(): void
    {
        if (! (bool) config('future_trade.binance_rest.circuit_breaker', true)) {
            return;
        }

        $banUntil = (int) Cache::get(self::BAN_UNTIL_KEY, 0);
        if ($banUntil > time()) {
            throw new RuntimeException('binance_rest_circuit_open: Binance REST banned/rate-limited for '.($banUntil - time()).'s. Last error: '.(string) Cache::get(self::LAST_ERROR_KEY, 'unknown'));
        }
    }

    public function throttle(): void
    {
        $minIntervalMs = max(0, (int) config('future_trade.binance_rest.min_interval_ms', 500));
        if ($minIntervalMs <= 0) {
            return;
        }

        $nowMs = (int) floor(microtime(true) * 1000);
        $lastCallAt = (int) Cache::get(self::LAST_CALL_AT_KEY, 0);
        $waitMs = $lastCallAt > 0 ? ($minIntervalMs - ($nowMs - $lastCallAt)) : 0;
        if ($waitMs > 0) {
            usleep($waitMs * 1000);
            $nowMs = (int) floor(microtime(true) * 1000);
        }

        Cache::put(self::LAST_CALL_AT_KEY, $nowMs, 60);
    }

    public function recordResponse(Response $response): void
    {
        if ($this->isBanResponse($response)) {
            $this->open($response->body());

            return;
        }

        if ($response->successful()) {
            $this->recordSuccess();
        }
    }

    public function recordSuccess(): void
    {
        Cache::put(self::LAST_OK_AT_KEY, now()->toIso8601String(), now()->addDay());
    }

    public function status(): array
    {
        $banUntil = (int) Cache::get(self::BAN_UNTIL_KEY, 0);

        return [
            'enabled' => (bool) config('future_trade.binance_rest.circuit_breaker', true),
            'open' => $banUntil > time(),
            'ban_until' => $banUntil > 0 ? date('c', $banUntil) : null,
            'remaining_seconds' => $banUntil > time() ? $banUntil - time() : 0,
            'last_error' => Cache::get(self::LAST_ERROR_KEY),
            'last_ok_at' => Cache::get(self::LAST_OK_AT_KEY),
            'min_interval_ms' => (int) config('future_trade.binance_rest.min_interval_ms', 500),
        ];
    }

    private function isBanResponse(Response $response): bool
    {
        if (in_array($response->status(), [418, 429], true)) {
            return true;
        }

        return (int) ($response->json('code') ?? 0) === -1003
            || str_contains(strtolower((string) $response->body()), 'banned until');
    }

    private function open(string $body): void
    {
        $banUntil = $this->parseBanUntil($body) ?? (time() + max(60, (int) config('future_trade.binance_rest.ban_cooldown_seconds', 3600)));
        $ttl = max(60, $banUntil - time());

        Cache::put(self::BAN_UNTIL_KEY, $banUntil, $ttl);
        Cache::put(self::LAST_ERROR_KEY, mb_substr($body, 0, 500), $ttl);
    }

    private function parseBanUntil(string $body): ?int
    {
        if (preg_match('/banned until\s+(\d{10,16})/i', $body, $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1];

        return $value > 9999999999 ? (int) floor($value / 1000) : $value;
    }
}
