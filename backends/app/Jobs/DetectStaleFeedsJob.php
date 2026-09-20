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
        $activeWorkspaceEntries = $this->activeWorkspaceSymbols();

        foreach ($activeWorkspaceEntries as $workspaceId => $entries) {
            $stale = [];
            $fresh = [];
            // Genuine freshness confirmations only — unlike $fresh (which
            // also absorbs the market_closed skip below for its existing
            // logging/context use), this array is the sole basis for the
            // safety-net clears further down. A market-closed skip means "we
            // didn't check," not "we confirmed the feed is alive," so it
            // must never be able to unlatch a breaker.
            $confirmedFreshForClear = [];

            // Each entry is a (symbol, market_type) pair, never a bare
            // symbol string: the SAME symbol (e.g. BTCUSDT) can appear here
            // twice -- once for a spot Ratio Bot and once for a futures
            // Future Trade/Ratio Bot bot in the same workspace -- and each
            // must be checked against its own market's feed and latch its
            // own, independently-tracked breaker. Merging them back into one
            // undifferentiated symbol list here is exactly the bug this
            // fixes: a stale futures feed must never be reported "fresh"
            // because a same-symbol spot feed happened to be healthy (or
            // vice versa).
            foreach ($entries as $entry) {
                $symbol = $entry['symbol'];
                $marketType = $entry['market_type'];
                // Both the canonical provider (1st arg) AND the legacy
                // exchange lookup (3rd arg) must be market-type-suffixed,
                // matching RealtimeMarketFeedService::ingest()'s own choice
                // of canonical provider ($this->exchangeKey($marketType), not
                // a hardcoded 'binance') -- verified empirically: passing a
                // bare 'binance' provider for both markets (mirroring
                // RatioBotConfig::forRatioBot()'s pattern) let a stale
                // futures check silently fall back to and "find" the SPOT
                // tick's canonical quote (same provider, same canonical
                // instrument), reporting the futures feed fresh when it
                // never received a tick at all. Using the market-type-aware
                // provider for both arguments closes that fallback
                // collision, not just the legacy-price one.
                $exchangeKey = $marketType === 'futures' ? 'binance_futures' : 'binance';

                $snapshot = $snapshots->getLiveSnapshot($exchangeKey, $symbol, $exchangeKey, $this->thresholdSeconds);
                $health = (array) ($snapshot['health'] ?? []);
                if (($health['state'] ?? null) === FeedState::MARKET_CLOSED->value) {
                    $fresh[] = ['symbol' => $symbol, 'market_type' => $marketType, 'reason' => 'market_closed_not_stale'];
                    continue;
                }

                if ((bool) ($snapshot['is_fresh'] ?? false)) {
                    $fresh[] = [
                        'symbol' => $symbol,
                        'market_type' => $marketType,
                        'received_at' => $snapshot['received_at'] ?? null,
                        'age_ms' => $snapshot['age_ms'] ?? null,
                    ];
                    $confirmedFreshForClear[] = [
                        'symbol' => $symbol,
                        'market_type' => $marketType,
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
                    if ($breakers->isStaleFeedBreakerActive((string) $workspaceId, $symbol, $marketType)) {
                        $breakers->clearStaleFeedBreaker((string) $workspaceId, [
                            'symbol' => $symbol,
                            'market_type' => $marketType,
                            'received_at' => $snapshot['received_at'] ?? null,
                            'age_ms' => $snapshot['age_ms'] ?? null,
                            'reason' => 'scheduled_detector_confirmed_fresh_safety_net',
                        ], 'stale_feed_detector_safety_net', $symbol, $marketType);
                    }

                    continue;
                }

                $staleEntry = [
                    'symbol' => $symbol,
                    'market_type' => $marketType,
                    'received_at' => $snapshot['received_at'] ?? null,
                    'age_ms' => $snapshot['age_ms'] ?? null,
                    'connection_state' => $snapshot['connection_state'] ?? null,
                    'reason' => $snapshot['stale_reason'] ?? 'selected_symbol_snapshot_not_fresh',
                ];
                $stale[] = $staleEntry;

                // Symbol-and-market-scoped breaker: only bots trading THIS
                // symbol in THIS market are gated. Kept alongside the
                // workspace-wide breaker below (still activated for every
                // other consumer of isStaleFeedBreakerActive()/
                // staleFeedState() that isn't symbol-aware yet) so a stale
                // SOLUSDT feed can no longer block a healthy BTCUSDT bot in
                // the same workspace, and a stale futures BTCUSDT feed can
                // no longer block (or be masked by) a healthy spot BTCUSDT
                // bot in the same workspace, or vice versa.
                $breakers->activateStaleFeedBreaker((string) $workspaceId, [
                    'symbol' => $symbol,
                    'market_type' => $marketType,
                    'threshold_seconds' => $this->thresholdSeconds,
                    'reason' => 'selected_symbol_stale',
                ] + $staleEntry, 'monitoring', $symbol, $marketType);

                $recovery->requestRecovery('binance', $symbol, StreamType::QUOTE, 'scheduled_stale_feed_check', [
                    'workspace_id' => (string) $workspaceId,
                    'market_type' => $marketType,
                    'threshold_seconds' => $this->thresholdSeconds,
                    'snapshot' => $snapshot,
                ]);
            }

            if ($stale !== []) {
                $breakers->activateStaleFeedBreaker((string) $workspaceId, [
                    'symbols' => $entries,
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
            // requires every selected (symbol, market_type) entry to have
            // been confirmed fresh ($entries !== [] guards the zero-entries
            // case defensively, matching the reference implementation's
            // $allFresh = $symbols !== []; initialization).
            $allSelectedSymbolsConfirmedFresh = $entries !== [] && count($confirmedFreshForClear) === count($entries);

            if ($allSelectedSymbolsConfirmedFresh && $breakers->isStaleFeedBreakerActive((string) $workspaceId)) {
                $breakers->clearStaleFeedBreaker((string) $workspaceId, [
                    'symbols' => $entries,
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
     * Returns, per workspace, a deduplicated list of (symbol, market_type)
     * pairs -- NEVER a bare symbol list. A RatioBotConfig can itself be
     * market_type=spot or market_type=futures (see
     * RatioBotConfig::marketType()), and FutureTradeBotConfig is always
     * futures; a workspace running a spot Ratio Bot and a futures bot on the
     * SAME symbol string must produce two independently-tracked entries
     * here, never one collided one -- that collision (and the hardcoded
     * spot-only 'binance' snapshot lookup that used to accompany it in
     * handle()) was the actual bug.
     *
     * @return array<string, array<int, array{symbol: string, market_type: string}>>
     */
    private function activeWorkspaceSymbols(): array
    {
        $workspaceEntries = [];

        RatioBotConfig::query()
            ->where('enabled', true)
            ->get(['workspace_id', 'symbol', 'market_type'])
            ->each(function (RatioBotConfig $config) use (&$workspaceEntries): void {
                $workspaceId = (string) $config->workspace_id;
                $symbol = $config->normalizedSymbol();

                if ($workspaceId === '' || $symbol === '') {
                    return;
                }

                $workspaceEntries[$workspaceId][] = [
                    'symbol' => $symbol,
                    'market_type' => $config->marketType(),
                ];
            });

        FutureTradeBotConfig::query()
            ->with('state')
            ->where('enabled', true)
            ->get()
            ->each(function (FutureTradeBotConfig $config) use (&$workspaceEntries): void {
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
                    // FutureTradeBotConfig has no exchange/market_type
                    // column of its own -- it is always Binance futures.
                    $workspaceEntries[$workspaceId][] = [
                        'symbol' => $symbol,
                        'market_type' => 'futures',
                    ];
                }
            });

        return collect($workspaceEntries)
            ->map(function (array $entries) {
                $unique = [];
                foreach ($entries as $entry) {
                    $key = $entry['market_type'] . ':' . $entry['symbol'];
                    $unique[$key] = $entry;
                }

                return array_values($unique);
            })
            ->filter(fn (array $entries) => $entries !== [])
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
