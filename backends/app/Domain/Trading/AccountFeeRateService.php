<?php

namespace App\Domain\Trading;

use App\Domain\Trading\Providers\BinanceTimedFuturesProvider;
use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pre-trade taker fee for the fee-aware decision: the connected Binance
 * USDⓈ-M account's REAL commission (`GET /fapi/v1/commissionRate`, via
 * BinanceTimedFuturesProvider's existing signed-request client) instead of
 * the flat `trading.binance_futures_taker_fee_rate` default.
 *
 * Contract, because it sits on a live decision path:
 *  - NEVER throws. Every failure (no integration, missing credentials,
 *    4xx/5xx, timeout, malformed or implausible body, cache outage) returns
 *    `taker_rate: null`, which every caller treats as "use the config rate",
 *    and `source: config` with a short machine-readable `reason`.
 *  - Success is cached for 1h per integration + environment + symbol in the
 *    application cache store (Redis only when CACHE_STORE=redis on the
 *    server). Failures are cached for 60s so an outage cannot add a network
 *    timeout to every 5s forecast poll.
 *  - Never logs the API key, secret or request signature. A transport error
 *    message can embed the signed URL (Guzzle appends "for <uri>"), so the
 *    `signature=` value is redacted before anything is logged or returned.
 *
 * Flag checks (`quick_trade.use_account_fee_rate`,
 * `robot_trade.use_account_fee_rate`) live at the call sites; this class only
 * answers "what is this account's rate".
 */
class AccountFeeRateService
{
    public const SOURCE_ACCOUNT = 'account';

    public const SOURCE_CONFIG = 'config';

    private const CACHE_TTL_SECONDS = 3600;

    private const FAILURE_CACHE_TTL_SECONDS = 60;

    private const REQUEST_TIMEOUT_SECONDS = 3;

    /** Sanity ceiling: Binance USDⓈ-M taker tiers are well under 0.1%. */
    private const MAX_PLAUSIBLE_RATE = 0.01;

    public function __construct(
        private readonly BinanceTimedFuturesProvider $binance,
        private readonly TradingIntegrationResolver $integrations,
    ) {
    }

    /**
     * @return array{taker_rate: float|null, maker_rate: float|null, source: string, reason: string|null, cached: bool}
     */
    public function resolveForWorkspace(string $workspaceId, ?string $provider, ?string $integrationId, string $symbol): array
    {
        if (strtolower(trim((string) ($provider ?: 'binance'))) !== 'binance') {
            return $this->fallback('provider_not_supported');
        }
        if (trim($workspaceId) === '' || trim($symbol) === '') {
            return $this->fallback('missing_scope');
        }

        try {
            $integration = $this->integrations->resolve($workspaceId, 'binance', $integrationId);
        } catch (Throwable) {
            return $this->fallback('integration_unavailable');
        }

        return $this->resolveForIntegration($integration, $symbol);
    }

    /**
     * @return array{taker_rate: float|null, maker_rate: float|null, source: string, reason: string|null, cached: bool}
     */
    public function resolveForIntegration(Integration $integration, string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return $this->fallback('missing_scope');
        }
        if (strtolower((string) $integration->provider_key) !== 'binance') {
            return $this->fallback('provider_not_supported');
        }

        $key = sprintf(
            'trading:account_fee_rate:v1:%s:%s:%s',
            (string) $integration->id,
            $this->binance->environmentFor($integration),
            $symbol,
        );

        $cached = $this->cacheGet($key);
        if (is_array($cached)) {
            return ($cached['ok'] ?? false) === true
                ? $this->account((float) $cached['taker'], $cached['maker'] !== null ? (float) $cached['maker'] : null, true)
                : $this->fallback((string) ($cached['reason'] ?? 'lookup_failed'), true);
        }

        try {
            $body = $this->binance->commissionRate($integration, $symbol, self::REQUEST_TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            $this->logFailure($integration, $symbol, 'request_failed', $e);
            $this->cachePut($key, ['ok' => false, 'reason' => 'request_failed'], self::FAILURE_CACHE_TTL_SECONDS);

            return $this->fallback('request_failed');
        }

        $taker = $this->plausibleRate($body['takerCommissionRate'] ?? null);
        $bodySymbol = strtoupper((string) ($body['symbol'] ?? $symbol));
        if ($taker === null || $bodySymbol !== $symbol) {
            $this->logFailure($integration, $symbol, 'malformed_response');
            $this->cachePut($key, ['ok' => false, 'reason' => 'malformed_response'], self::FAILURE_CACHE_TTL_SECONDS);

            return $this->fallback('malformed_response');
        }
        $maker = $this->plausibleRate($body['makerCommissionRate'] ?? null);

        $this->cachePut($key, ['ok' => true, 'taker' => $taker, 'maker' => $maker], self::CACHE_TTL_SECONDS);

        return $this->account($taker, $maker, false);
    }

    private function plausibleRate(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $rate = (float) $value;
        if (! is_finite($rate) || $rate < 0.0 || $rate > self::MAX_PLAUSIBLE_RATE) {
            return null;
        }

        return $rate;
    }

    /** @return array{taker_rate: float, maker_rate: float|null, source: string, reason: null, cached: bool} */
    private function account(float $taker, ?float $maker, bool $cached): array
    {
        return ['taker_rate' => $taker, 'maker_rate' => $maker, 'source' => self::SOURCE_ACCOUNT, 'reason' => null, 'cached' => $cached];
    }

    /** @return array{taker_rate: null, maker_rate: null, source: string, reason: string, cached: bool} */
    private function fallback(string $reason, bool $cached = false): array
    {
        return ['taker_rate' => null, 'maker_rate' => null, 'source' => self::SOURCE_CONFIG, 'reason' => $reason, 'cached' => $cached];
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function cachePut(string $key, array $value, int $ttl): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (Throwable) {
        }
    }

    private function logFailure(Integration $integration, string $symbol, string $reason, ?Throwable $e = null): void
    {
        try {
            Log::warning('account_fee_rate_lookup_failed', [
                'integration_id' => (string) $integration->id,
                'symbol' => $symbol,
                'reason' => $reason,
                'error_class' => $e !== null ? $e::class : null,
                'error' => $e !== null ? $this->redact($e->getMessage()) : null,
            ]);
        } catch (Throwable) {
        }
    }

    /** Strips signed-URL parameters so a key/signature can never reach a log line. */
    private function redact(string $message): string
    {
        $message = (string) preg_replace('/(signature|X-MBX-APIKEY|api_key|api_secret|apiKey|secret)([=:]\s*)[^&\s"\']+/i', '$1$2[redacted]', $message);

        return mb_substr($message, 0, 300);
    }
}
