<?php

namespace App\Domain\Ops;

use App\Models\ExchangeConnection;
use App\Models\Ledger;
use App\Models\MarketTick;
use RuntimeException;

class SafeLiveGate
{
    public function __construct(private readonly OperationalBreakerService $breakers)
    {
    }

    public function ensureLiveAllowed(string $workspaceId, string $symbol, float $notional, string $provider = 'binance'): void
    {
        if (!env('LIVE_TRADING_ENABLED', false)) {
            throw new RuntimeException('Live trading disabled');
        }

        $provider = strtolower(trim($provider));
        if ($this->breakers->isProviderOutageActive($workspaceId, $provider)) {
            throw new RuntimeException("Exchange outage breaker active for {$provider}.");
        }

        if ($this->breakers->isStaleFeedBreakerActive($workspaceId)) {
            throw new RuntimeException('Stale feed breaker active for live trading.');
        }

        $latestTick = MarketTick::orderByDesc('occurred_at')->first();
        $staleThreshold = max(10, (int) env('LIVE_TRADING_STALE_FEED_THRESHOLD_SEC', 60));
        if ($latestTick?->occurred_at && now()->diffInSeconds($latestTick->occurred_at) > $staleThreshold) {
            throw new RuntimeException('Latest market feed is stale for live trading.');
        }

        $symbol = strtoupper($symbol);
        $allowlist = array_filter(explode(',', env('LIVE_TRADING_SYMBOL_ALLOWLIST', 'BTCUSDT,ETHUSDT')));
        if ($allowlist && !in_array($symbol, $allowlist, true)) {
            throw new RuntimeException('Symbol not allowlisted for live');
        }

        $ledger = Ledger::where('workspace_id', $workspaceId)->first();
        $equity = $ledger?->balance ?? 0;
        $maxExposurePct = (float) env('LIVE_TRADING_MAX_EXPOSURE_PCT', 20);
        if ($equity > 0 && ($notional / $equity) * 100 > $maxExposurePct) {
            throw new RuntimeException('Exposure limit exceeded');
        }
    }

    public function ensureConnection(string $workspaceId, string $provider = 'binance'): void
    {
        if ($this->breakers->isProviderOutageActive($workspaceId, $provider)) {
            throw new RuntimeException("Exchange outage breaker active for {$provider}.");
        }

        $conn = ExchangeConnection::where('workspace_id', $workspaceId)
            ->where('is_enabled', true)
            ->where('is_active', true)
            ->first();
        if (!$conn) {
            throw new RuntimeException('No enabled exchange connection');
        }
    }
}
