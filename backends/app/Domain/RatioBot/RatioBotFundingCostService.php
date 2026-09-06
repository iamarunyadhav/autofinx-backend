<?php

namespace App\Domain\RatioBot;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Integrations\IntegrationManager;
use App\Models\Integration;
use App\Models\RatioBotConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Funding-rate erosion tracking (Part C.2). `/fapi/v1/premiumIndex`
 * (BinanceFuturesClient::fetchMarkPrice()) already returns `lastFundingRate`
 * over the wire -- this is the first thing in the codebase that reads it.
 * Deliberately capture-and-surface only in this pass: this does NOT feed
 * into any recovery/decision logic yet (that integration is scoped to the
 * separate, not-yet-built Part B design pass).
 */
class RatioBotFundingCostService
{
    public function __construct(
        private readonly IntegrationManager $integrations,
    ) {
    }

    /**
     * Call once per reconcile tick for an open futures position. Binance
     * applies funding at fixed 8-hour UTC boundaries (00:00/08:00/16:00)
     * regardless of how often this is called -- accumulation only actually
     * adds cost the first time this runs after a new boundary has passed,
     * so a 5-minute reconcile cadence still applies each funding charge
     * exactly once.
     */
    public function accumulate(RatioBotConfig $config, Integration $integration, array $workspaceConfig): void
    {
        $state = $config->state()->first();
        if (! $state || (float) ($state->last_position_qty ?? 0) <= 0) {
            return;
        }

        try {
            $markData = $this->client($integration, $workspaceConfig)->fetchMarkPrice($config->normalizedSymbol());
        } catch (Throwable $e) {
            Log::info('[RatioBotFundingCost] Could not fetch funding rate this tick', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if (! isset($markData['lastFundingRate'])) {
            return;
        }

        $fundingRate = (float) $markData['lastFundingRate'];
        $boundary = $this->lastFundingBoundary(now());
        $alreadyApplied = $state->last_funding_applied_at !== null && $state->last_funding_applied_at->gte($boundary);

        $state->update(['last_funding_rate' => $fundingRate]);

        if ($alreadyApplied) {
            return;
        }

        $markPrice = (float) ($markData['markPrice'] ?? 0);
        $notional = abs((float) $state->last_position_qty) * max(0.0, $markPrice);
        $cost = $notional * $fundingRate;

        $state->update([
            'estimated_funding_cost' => round((float) $state->estimated_funding_cost + $cost, 8),
            'last_funding_applied_at' => $boundary,
        ]);
    }

    /**
     * Call whenever a position fully closes so the NEXT position's tracker
     * starts clean rather than carrying over a prior lifecycle's drag.
     */
    public function resetForClosedPosition(RatioBotConfig $config): void
    {
        $config->state()->first()?->update([
            'estimated_funding_cost' => 0,
            'last_funding_rate' => null,
            'last_funding_applied_at' => null,
        ]);
    }

    private function lastFundingBoundary(Carbon $now): Carbon
    {
        $hour = intdiv($now->hour, 8) * 8;

        return $now->copy()->setTime($hour, 0, 0);
    }

    private function client(Integration $integration, array $config): BinanceFuturesClient
    {
        $creds = $this->integrations->decryptSecrets($integration->secrets_encrypted);
        if ($creds === []) {
            throw new RuntimeException('Binance futures credentials are missing.');
        }

        return new BinanceFuturesClient(
            apiKey: (string) ($creds['api_key'] ?? $creds['key'] ?? ''),
            apiSecret: (string) ($creds['api_secret'] ?? $creds['secret'] ?? ''),
            baseUrl: (string) (($config['futures_force_testnet'] ?? true)
                ? config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com')
                : config('services.binance.futures_api_url', 'https://fapi.binance.com')),
        );
    }
}
