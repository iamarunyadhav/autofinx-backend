<?php

namespace App\Jobs;

use App\Domain\Ops\OperationalBreakerService;
use App\Domain\MarketData\Enums\FeedState;
use App\Domain\MarketData\Enums\StreamType;
use App\Domain\MarketData\MarketFreshnessService;
use App\Domain\MarketData\MarketRecoveryManager;
use App\Domain\MarketData\MarketSnapshotService;
use App\Models\FutureTradeBotConfig;
use App\Models\RatioBotConfig;
use App\Models\RiskEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class DetectStaleFeedsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 55;
    public int $thresholdSeconds;

    public function __construct(int $thresholdSeconds = 60)
    {
        $this->onQueue('monitoring_safe');
        $this->thresholdSeconds = $thresholdSeconds;
    }

    public function handle(OperationalBreakerService $breakers, MarketFreshnessService $freshness, MarketRecoveryManager $recovery, MarketSnapshotService $snapshots): void
    {
        $now = now();
        $activeWorkspaceSymbols = $this->activeWorkspaceSymbols();

        foreach ($activeWorkspaceSymbols as $workspaceId => $symbols) {
            $stale = [];
            $fresh = [];
            // Genuine freshness confirmations only — unlike $fresh (which
            // also absorbs the market_closed skip below for its existing
            // logging/context use), this array is the sole basis for the
            // safety-net clears further down. A market-closed skip means "we
            // didn't check," not "we confirmed the feed is alive," so it
            // must never be able to unlatch a breaker.
            $confirmedFreshForClear = [];

            foreach ($symbols as $symbol) {
                $snapshot = $snapshots->getLiveSnapshot('binance', $symbol, 'binance', $this->thresholdSeconds);
                $health = (array) ($snapshot['health'] ?? []);
                if (($health['state'] ?? null) === FeedState::MARKET_CLOSED->value) {
                    $fresh[] = ['symbol' => $symbol, 'reason' => 'market_closed_not_stale'];
                    continue;
                }

                if ((bool) ($snapshot['is_fresh'] ?? false)) {
                    $fresh[] = [
                        'symbol' => $symbol,
                        'received_at' => $snapshot['received_at'] ?? null,
                        'age_ms' => $snapshot['age_ms'] ?? null,
                    ];
                    $confirmedFreshForClear[] = [
                        'symbol' => $symbol,
                        'received_at' => $snapshot['received_at'] ?? null,
                        'age_ms' => $snapshot['age_ms'] ?? null,
                    ];

                    // Conservative safety net: this job's OWN freshness check
                    // (not elapsed time, not the absence of a stale
                    // detection) just confirmed this symbol is genuinely
                    // alive. If its breaker somehow latched active anyway
                    // (e.g. a transient delay that straddled a prior run
                    // before websocket ingest could clear it), self-heal it
                    // here rather than waiting on a live tick.
                    if ($breakers->isStaleFeedBreakerActive((string) $workspaceId, $symbol)) {
                        $breakers->clearStaleFeedBreaker((string) $workspaceId, [
                            'symbol' => $symbol,
                            'received_at' => $snapshot['received_at'] ?? null,
                            'age_ms' => $snapshot['age_ms'] ?? null,
                            'reason' => 'scheduled_detector_confirmed_fresh_safety_net',
                        ], 'stale_feed_detector_safety_net', $symbol);
                    }

                    continue;
                }

                $staleEntry = [
                    'symbol' => $symbol,
                    'received_at' => $snapshot['received_at'] ?? null,
                    'age_ms' => $snapshot['age_ms'] ?? null,
                    'connection_state' => $snapshot['connection_state'] ?? null,
                    'reason' => $snapshot['stale_reason'] ?? 'selected_symbol_snapshot_not_fresh',
                ];
                $stale[] = $staleEntry;

                // Symbol-scoped breaker: only bots trading THIS symbol are
                // gated. Kept alongside the workspace-wide breaker below
                // (still activated for every other consumer of
                // isStaleFeedBreakerActive()/staleFeedState() that isn't
                // symbol-aware yet) so a stale SOLUSDT feed can no longer
                // block a healthy BTCUSDT bot in the same workspace.
                $breakers->activateStaleFeedBreaker((string) $workspaceId, [
                    'symbol' => $symbol,
                    'threshold_seconds' => $this->thresholdSeconds,
                    'reason' => 'selected_symbol_stale',
                ] + $staleEntry, 'monitoring', $symbol);

                $recovery->requestRecovery('binance', $symbol, StreamType::QUOTE, 'scheduled_stale_feed_check', [
                    'workspace_id' => (string) $workspaceId,
                    'threshold_seconds' => $this->thresholdSeconds,
                    'snapshot' => $snapshot,
                ]);
            }

            if ($stale !== []) {
                $breakers->activateStaleFeedBreaker((string) $workspaceId, [
                    'symbols' => $symbols,
                    'stale_symbols' => $stale,
                    'fresh_symbols' => $fresh,
                    'threshold_seconds' => $this->thresholdSeconds,
                    'reason' => 'one_or_more_selected_symbols_stale',
                ]);

                $this->recordStaleRiskEvent((string) $workspaceId, (string) ($stale[0]['symbol'] ?? ''), $now, $stale[0]);
                continue;
            }

            // Breaker clearing is intentionally owned by websocket ingest after
            // a genuinely new canonical tick is written for the selected
            // symbols. The scheduled detector is read-only on recovery so a
            // process restart or stale compatibility key cannot unlatch trading.
            // Conservative exception: the safety net above (per-symbol) and
            // below (workspace-wide) only fires off THIS run's own confirmed
            // freshness evidence ($confirmedFreshForClear) — never off
            // elapsed time, never merely because no stale symbol was
            // detected this run, and never off a market-closed skip (which
            // proves nothing about the feed being alive). $stale === []
            // alone is not sufficient: a workspace whose only symbol was
            // market-closed this run also has $stale === [] with zero
            // genuine confirmations, so the workspace-wide clear additionally
            // requires every selected symbol to have been confirmed fresh
            // ($symbols !== [] guards the zero-symbols case defensively,
            // matching the reference implementation's $allFresh = $symbols
            // !== []; initialization).
            $allSelectedSymbolsConfirmedFresh = $symbols !== [] && count($confirmedFreshForClear) === count($symbols);

            if ($allSelectedSymbolsConfirmedFresh && $breakers->isStaleFeedBreakerActive((string) $workspaceId)) {
                $breakers->clearStaleFeedBreaker((string) $workspaceId, [
                    'symbols' => $symbols,
                    'fresh_symbols' => $fresh,
                    'reason' => 'scheduled_detector_confirmed_all_fresh_safety_net',
                ], 'stale_feed_detector_safety_net');
            }
        }
    }

    public function uniqueId(): string
    {
        return 'detect-stale-feeds:' . $this->thresholdSeconds;
    }

    /**
     * @return array<string, string[]>
     */
    private function activeWorkspaceSymbols(): array
    {
        $workspaceSymbols = [];

        RatioBotConfig::query()
            ->where('enabled', true)
            ->get(['workspace_id', 'symbol'])
            ->each(function (RatioBotConfig $config) use (&$workspaceSymbols): void {
                $workspaceId = (string) $config->workspace_id;
                $symbol = strtoupper(trim((string) $config->symbol));

                if ($workspaceId === '' || $symbol === '') {
                    return;
                }

                $workspaceSymbols[$workspaceId][] = $symbol;
            });

        FutureTradeBotConfig::query()
            ->with('state')
            ->where('enabled', true)
            ->get()
            ->each(function (FutureTradeBotConfig $config) use (&$workspaceSymbols): void {
                $workspaceId = (string) $config->workspace_id;
                if ($workspaceId === '') {
                    return;
                }

                $settings = $config->mergedSettings();
                $symbols = array_map(
                    static fn ($symbol) => strtoupper(trim((string) $symbol)),
                    (array) ($settings['symbols'] ?? config('future_trade.defaults.symbols', []))
                );

                $currentSymbol = strtoupper(trim((string) ($config->state?->current_symbol ?? '')));
                if ($currentSymbol !== '') {
                    $symbols[] = $currentSymbol;
                }

                foreach (array_filter($symbols) as $symbol) {
                    $workspaceSymbols[$workspaceId][] = $symbol;
                }
            });

        return collect($workspaceSymbols)
            ->map(fn (array $symbols) => array_values(array_unique(array_filter($symbols))))
            ->filter(fn (array $symbols) => $symbols !== [])
            ->all();
    }

    private function recordStaleRiskEvent(string $workspaceId, string $symbol, \Illuminate\Support\Carbon $now, array $snapshot): void
    {
        if ($symbol === '') {
            return;
        }

        $recentDuplicate = RiskEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'feed_stale')
            ->where('triggered_at', '>=', $now->copy()->subSeconds(max($this->thresholdSeconds * 2, 120)))
            ->where('reason_json->symbol', $symbol)
            ->exists();

        if ($recentDuplicate) {
            return;
        }

        RiskEvent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'type' => 'feed_stale',
            'severity' => 'warning',
            'reason_json' => [
                'symbol' => $symbol,
                'received_at' => $snapshot['received_at'] ?? null,
                'age_ms' => $snapshot['age_ms'] ?? null,
                'threshold_seconds' => $this->thresholdSeconds,
                'incident_lifecycle' => 'opened',
            ],
            'triggered_at' => $now,
        ]);
    }
}
