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
                    continue;
                }

                $stale[] = [
                    'symbol' => $symbol,
                    'received_at' => $snapshot['received_at'] ?? null,
                    'age_ms' => $snapshot['age_ms'] ?? null,
                    'connection_state' => $snapshot['connection_state'] ?? null,
                    'reason' => $snapshot['stale_reason'] ?? 'selected_symbol_snapshot_not_fresh',
                ];

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
