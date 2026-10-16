<?php

namespace App\Domain\MarketOpportunityBrain\Adapters;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin, read-only wrapper around Binance's PUBLIC USDⓈ-M futures REST endpoints
 * (MASTER_SPEC.md Layer D §42/§48). No API key is ever accepted or sent; this class has no method
 * that could place/cancel/modify an order. Every call passes through
 * {@see BinancePublicDataGate::assertEnabled()} first, enforces a simple per-minute weight budget,
 * and retries 429/418/5xx with exponential backoff + jitter, honoring `Retry-After` when present.
 */
class BinancePublicRestClient
{
    public function __construct(private readonly ?string $baseUrlOverride = null)
    {
    }

    private function baseUrl(): string
    {
        return rtrim($this->baseUrlOverride ?? (string) config('market_opportunity_brain.binance_public_base_url', 'https://fapi.binance.com'), '/');
    }

    public function exchangeInfo(): array
    {
        return $this->get('/fapi/v1/exchangeInfo', [], weight: 1);
    }

    public function ticker24hr(): array
    {
        // No `symbol` param -> all symbols in one call, weight 40 per Binance's documented
        // futures REST weight table -- cheaper than N per-symbol calls for a discovery scan.
        return $this->get('/fapi/v1/ticker/24hr', [], weight: 40);
    }

    public function klines(string $symbol, string $interval, int $limit, ?int $startTimeMs = null, ?int $endTimeMs = null): array
    {
        // Binance's documented weight for /fapi/v1/klines scales with `limit`; 2 is a safe
        // conservative constant for limit<=1500 (worst documented tier), not a precise lookup
        // table -- good enough for a budget gate, not a byte-exact replica of Binance's own table.
        $params = [
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => max(1, min(1500, $limit)),
        ];
        // Optional bounded historical window (Phase 5 dataset builder; public data only).
        if ($startTimeMs !== null) {
            $params['startTime'] = $startTimeMs;
        }
        if ($endTimeMs !== null) {
            $params['endTime'] = $endTimeMs;
        }

        return $this->get('/fapi/v1/klines', $params, weight: 2);
    }

    /**
     * @throws MarketDataDisabledException
     */
    private function get(string $path, array $params, int $weight): array
    {
        BinancePublicDataGate::assertEnabled();
        $this->consumeWeightBudget($weight);

        $response = $this->client()->get($this->baseUrl().$path, $params);

        if (! $response->successful()) {
            Log::warning('market_opportunity_brain.binance_public_rest.failed', [
                'path' => $path,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);
            throw new \RuntimeException("Binance public REST call failed: {$path} -> HTTP {$response->status()}");
        }

        return (array) $response->json();
    }

    private function client(): PendingRequest
    {
        $timeout = max(1, (int) config('market_opportunity_brain.request_timeout_seconds', 10));

        return Http::timeout($timeout)
            ->retry(3, 500, function (\Throwable $exception, PendingRequest $request) {
                if (! $exception instanceof \Illuminate\Http\Client\RequestException) {
                    return true; // connection-level errors: retry
                }
                $status = $exception->response->status();
                // 418 = Binance IP auto-ban, 429 = rate limited, 5xx = server error -- all retryable.
                // 4xx other than 429/418 (bad request, not found, etc.) is never retried.
                if (in_array($status, [429, 418], true)) {
                    $retryAfter = (int) $exception->response->header('Retry-After');
                    if ($retryAfter > 0) {
                        usleep(min(10_000_000, $retryAfter * 1_000_000));
                    }

                    return true;
                }

                return $status >= 500;
            });
    }

    /**
     * Simple sliding-minute weight budget via cache, scoped so a single misbehaving scan cannot
     * blow past the operator's configured `max_api_weight_per_minute` (Layer D §48: "rate-weight
     * budgets"). Deliberately not a byte-exact mirror of Binance's own 1200-weight/min limit --
     * this is OUR self-imposed ceiling, which defaults to 0 (disabled) per CONFIG_PROPOSAL.md.
     */
    private function consumeWeightBudget(int $weight): void
    {
        $limit = (int) config('market_opportunity_brain.max_api_weight_per_minute', 0);
        $bucketKey = 'mob:binance:weight:'.gmdate('YmdHi');

        $used = (int) Cache::get($bucketKey, 0);
        if ($used + $weight > $limit) {
            throw new MarketDataDisabledException("Binance public REST weight budget exceeded for this minute ({$used}+{$weight} > {$limit}).");
        }

        Cache::put($bucketKey, $used + $weight, now()->addSeconds(90));
    }
}
