<?php

namespace App\Jobs;

use App\Domain\Ops\OperationalBreakerService;
use App\Models\FutureTradeBotConfig;
use App\Models\MarketTick;
use App\Models\MarketFeedStatus;
use App\Models\RatioBotConfig;
use App\Models\RiskEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
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

    public function handle(OperationalBreakerService $breakers): void
    {
        $now = now();
        $activeWorkspaceSymbols = $this->activeWorkspaceSymbols();
        $latestTick = MarketTick::orderByDesc('occurred_at')->first();
        $latestFeedStatus = Schema::hasTable('market_feed_status')
            ? MarketFeedStatus::whereNotNull('last_tick_at')->orderByDesc('last_tick_at')->first()
            : null;

        $latestTickAt = collect([
            $latestTick?->occurred_at,
            $latestFeedStatus?->last_tick_at,
        ])->filter()->sortDesc()->first();

        $globalFeedIsStale = $latestTickAt !== null
            && $latestTickAt->lt($now->copy()->subSeconds($this->thresholdSeconds));

        if ($globalFeedIsStale) {
            $breakers->activateStaleFeedBreaker(null, [
                'last_tick_at' => $latestTickAt?->toIso8601String(),
                'latest_workspace_id' => $latestFeedStatus?->workspace_id,
                'latest_symbol_id' => $latestFeedStatus?->symbol_id,
                'threshold_seconds' => $this->thresholdSeconds,
            ]);
        } else {
            $breakers->clearStaleFeedBreaker(null, [
                'last_tick_at' => $latestTickAt?->toIso8601String(),
                'latest_workspace_id' => $latestFeedStatus?->workspace_id,
                'latest_symbol_id' => $latestFeedStatus?->symbol_id,
                'threshold_seconds' => $this->thresholdSeconds,
            ]);
        }

        if (! Schema::hasTable('market_feed_status')) {
            return;
        }

        foreach ($activeWorkspaceSymbols as $workspaceId => $symbols) {
            $latestRelevantFeed = MarketFeedStatus::where('workspace_id', $workspaceId)
                ->whereIn('symbol', $symbols)
                ->whereNotNull('last_tick_at')
                ->orderByDesc('last_tick_at')
                ->first();

            if (! $latestRelevantFeed) {
                $breakers->activateStaleFeedBreaker((string) $workspaceId, [
                    'symbols' => $symbols,
                    'reason' => 'no_feed_status_for_active_symbols',
                    'threshold_seconds' => $this->thresholdSeconds,
                ]);

                continue;
            }

            $isWorkspaceFeedStale = $latestRelevantFeed->last_tick_at->lt($now->copy()->subSeconds($this->thresholdSeconds));

            if ($isWorkspaceFeedStale) {
                $breakers->activateStaleFeedBreaker((string) $workspaceId, [
                    'symbol' => $latestRelevantFeed->symbol,
                    'symbol_id' => $latestRelevantFeed->symbol_id,
                    'last_tick_at' => optional($latestRelevantFeed->last_tick_at)?->toIso8601String(),
                    'threshold_seconds' => $this->thresholdSeconds,
                ]);

                RiskEvent::create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'type' => 'feed_stale',
                    'severity' => 'warning',
                    'reason_json' => [
                        'symbol' => $latestRelevantFeed->symbol,
                        'symbol_id' => $latestRelevantFeed->symbol_id,
                        'last_tick_at' => $latestRelevantFeed->last_tick_at,
                        'threshold_seconds' => $this->thresholdSeconds,
                    ],
                    'triggered_at' => $now,
                ]);
            } else {
                $breakers->clearStaleFeedBreaker((string) $workspaceId, [
                    'symbol' => $latestRelevantFeed->symbol,
                    'symbol_id' => $latestRelevantFeed->symbol_id,
                    'last_tick_at' => optional($latestRelevantFeed->last_tick_at)?->toIso8601String(),
                    'threshold_seconds' => $this->thresholdSeconds,
                ]);
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
}
