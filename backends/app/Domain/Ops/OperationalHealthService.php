<?php

namespace App\Domain\Ops;

use App\Domain\Exchange\BinanceSpotHealthService;
use App\Domain\Intelligence\AiTradingStateService;
use App\Models\AiDecisionCycle;
use App\Models\AiTradingConfig;
use App\Models\ExternalContextSnapshot;
use App\Models\ExternalContextSourceHealth;
use App\Models\Incident;
use App\Models\MarketFeedStatus;
use App\Models\MarketTick;
use App\Models\OperationalDriftDetection;
use App\Models\Order;
use App\Models\RiskEvent;
use App\Models\SchedulerRun;
use App\Models\ExchangeConnection;
use Illuminate\Support\Facades\Schema;

class OperationalHealthService
{
    public function __construct(
        private readonly OperationalBreakerService $breakers,
        private readonly KillSwitchService $killSwitch,
        private readonly BinanceSpotHealthService $binanceHealth,
        private readonly AiTradingStateService $aiTradingState,
    ) {
    }

    public function summary(string $workspaceId): array
    {
        $now = now();
        $killSwitch = $this->killSwitch->state($workspaceId);
        $providerBreaker = $this->breakers->providerState($workspaceId, 'binance');
        $staleFeedBreaker = $this->breakers->staleFeedState($workspaceId);
        $restHealth = $this->binanceHealth->snapshot($workspaceId);
        $marketData = $this->marketDataHealth($workspaceId, $now);
        $streamHealth = $this->streamHealth($workspaceId, $now);
        $queueHealth = $this->queueHealth();
        $schedulerHealth = $this->schedulerHealth($now);
        $orderHealth = $this->orderHealth($workspaceId, $now);
        $aiHealth = $this->aiHealth($workspaceId, $now);
        $externalHealth = $this->externalContextHealth($workspaceId, $now);
        $driftHealth = $this->driftHealth($workspaceId);
        $incidents = $this->incidentHealth($workspaceId);

        $signals = [
            $killSwitch['active'] ?? false ? 'blocker' : null,
            $providerBreaker['active'] ?? false ? 'blocker' : null,
            $staleFeedBreaker['active'] ?? false ? 'blocker' : null,
            $incidents['critical_open'] > 0 ? 'blocker' : null,
            $queueHealth['state'] === 'blocked' ? 'blocker' : null,
            $schedulerHealth['state'] === 'blocked' ? 'blocker' : null,
            $marketData['state'] === 'blocked' ? 'blocker' : null,
            $streamHealth['state'] === 'blocked' ? 'blocker' : null,
            $orderHealth['state'] === 'blocked' ? 'blocker' : null,
            $aiHealth['state'] === 'blocked' ? 'blocker' : null,
            $externalHealth['state'] === 'blocked' ? 'blocker' : null,
            $driftHealth['state'] === 'blocked' ? 'blocker' : null,
        ];

        $warnings = array_values(array_filter(array_merge(
            $marketData['warnings'],
            $streamHealth['warnings'],
            $queueHealth['warnings'],
            $schedulerHealth['warnings'],
            $orderHealth['warnings'],
            $aiHealth['warnings'],
            $externalHealth['warnings'],
            $driftHealth['warnings'],
            $incidents['warnings']
        )));

        $blockers = array_values(array_filter(array_merge(
            $marketData['blockers'],
            $streamHealth['blockers'],
            $queueHealth['blockers'],
            $schedulerHealth['blockers'],
            $orderHealth['blockers'],
            $aiHealth['blockers'],
            $externalHealth['blockers'],
            $driftHealth['blockers'],
            $incidents['blockers'],
            $killSwitch['active'] ? ['kill_switch_active'] : [],
            $providerBreaker['active'] ? ['exchange_provider_breaker_active'] : [],
            $staleFeedBreaker['active'] ? ['stale_feed_active'] : []
        )));

        $state = $this->stateFromSignals($blockers, $warnings, array_filter($signals));
        $severity = match ($state) {
            'blocked' => 'critical',
            'degraded' => 'high',
            'caution' => 'medium',
            default => 'low',
        };

        return [
            'workspace_id' => $workspaceId,
            'state' => $state,
            'severity' => $severity,
            'blocked' => $state === 'blocked',
            'healthy' => $state === 'healthy',
            'generated_at' => $now->toIso8601String(),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'recommendations' => $this->recommendations($state, $blockers, $warnings),
            'counts' => [
                'open_incidents' => $incidents['open_incidents'],
                'critical_incidents' => $incidents['critical_open'],
                'pending_ai_decisions' => $aiHealth['pending_ai_decisions'],
                'uncertain_orders' => $orderHealth['uncertain_orders'],
                'stale_orders' => $orderHealth['stale_orders'],
                'order_failures_24h' => $orderHealth['order_failures_24h'],
                'open_drift_detections' => $driftHealth['open_drift_detections'],
                'queue_backlog' => $queueHealth['queue_backlog'],
                'failed_jobs' => $queueHealth['failed_jobs'],
            ],
            'checks' => [
                'kill_switch' => $killSwitch,
                'provider_breaker' => $providerBreaker,
                'stale_feed_breaker' => $staleFeedBreaker,
                'rest_health' => $restHealth,
                'market_data' => $marketData,
                'streams' => $streamHealth,
                'queue' => $queueHealth,
                'scheduler' => $schedulerHealth,
                'orders' => $orderHealth,
                'ai' => $aiHealth,
                'external_context' => $externalHealth,
                'drift' => $driftHealth,
                'incidents' => $incidents,
            ],
            'source_health' => [
                'binance_rest' => $restHealth,
                'external_context' => $externalHealth['source_health'],
                'operational_drift' => $driftHealth['latest'],
            ],
        ];
    }

    private function marketDataHealth(string $workspaceId, \Illuminate\Support\Carbon $now): array
    {
        $latestTick = MarketTick::query()
            ->orderByDesc('occurred_at')
            ->first();

        $workspaceFeed = null;
        if (Schema::hasTable('market_feed_status')) {
            $workspaceFeed = MarketFeedStatus::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('last_tick_at')
                ->first();
        }

        $lastTickAt = $workspaceFeed?->last_tick_at ?: $latestTick?->occurred_at;
        $ageSeconds = $lastTickAt ? $now->diffInSeconds($lastTickAt) : null;
        $threshold = (int) config('ops.live_readiness.scheduler_stale_minutes', 15) * 60;

        $blockers = [];
        $warnings = [];
        $state = 'healthy';

        if ($lastTickAt === null) {
            $warnings[] = 'market_data_unavailable';
            $state = 'caution';
        } elseif ($ageSeconds !== null && $ageSeconds >= ($threshold * 2)) {
            $blockers[] = 'stale_market_data';
            $state = 'blocked';
        } elseif ($ageSeconds !== null && $ageSeconds >= $threshold) {
            $warnings[] = 'market_data_stale';
            $state = 'caution';
        }

        return [
            'state' => $state,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'last_tick_at' => $lastTickAt?->toIso8601String(),
            'age_seconds' => $ageSeconds,
        ];
    }

    private function streamHealth(string $workspaceId, \Illuminate\Support\Carbon $now): array
    {
        $connections = ExchangeConnection::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', 'binance')
            ->where('is_active', true)
            ->get();

        if ($connections->isEmpty()) {
            return [
                'state' => 'caution',
                'blockers' => [],
                'warnings' => ['binance_user_stream_not_configured'],
                'active_connections' => 0,
                'healthy_connections' => 0,
                'connections' => [],
            ];
        }

        $connectionsPayload = [];
        $healthy = 0;
        $warnings = [];
        $blockers = [];

        foreach ($connections as $connection) {
            $stream = $this->breakers->streamState($connection);
            $connectionsPayload[] = [
                'connection_id' => $connection->id,
                'status' => $stream['status'] ?? 'idle',
                'last_keepalive_at' => $stream['last_keepalive_at'] ?? null,
                'last_error' => $stream['last_error'] ?? null,
                'restart_count' => (int) ($stream['restart_count'] ?? 0),
            ];

            $lastKeepalive = data_get($stream, 'last_keepalive_at');
            $ageMinutes = $lastKeepalive ? $now->diffInMinutes($lastKeepalive) : null;
            if (($stream['status'] ?? 'idle') === 'running' && ($ageMinutes === null || $ageMinutes < 45)) {
                $healthy++;
            } else {
                $warnings[] = 'user_stream_unhealthy:' . $connection->id;
            }

            if (($stream['status'] ?? 'idle') === 'error') {
                $blockers[] = 'user_stream_error:' . $connection->id;
            }
        }

        $state = $blockers !== [] ? 'blocked' : ($warnings !== [] ? 'caution' : 'healthy');

        return [
            'state' => $state,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'active_connections' => $connections->count(),
            'healthy_connections' => $healthy,
            'connections' => $connectionsPayload,
        ];
    }

    private function queueHealth(): array
    {
        $backlog = 0;
        $failedJobs = 0;
        $warnings = [];
        $blockers = [];

        if (Schema::hasTable('jobs')) {
            $backlog = \Illuminate\Support\Facades\DB::table('jobs')->count();
        }

        if (Schema::hasTable('failed_jobs')) {
            $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
        }

        $warningThreshold = (int) config('ops.live_readiness.queue_backlog_warning', 100);
        $blockThreshold = (int) config('ops.live_readiness.queue_backlog_block', 500);

        if ($backlog >= $blockThreshold) {
            $blockers[] = 'queue_backlog_blocked';
        } elseif ($backlog >= $warningThreshold) {
            $warnings[] = 'queue_backlog_warning';
        }

        if ($failedJobs > 0) {
            $warnings[] = 'failed_jobs_present';
        }

        $state = $blockers !== [] ? 'blocked' : ($warnings !== [] ? 'degraded' : 'healthy');

        return [
            'state' => $state,
            'blockers' => $blockers,
            'warnings' => array_values(array_unique($warnings)),
            'queue_backlog' => $backlog,
            'failed_jobs' => $failedJobs,
            'warning_threshold' => $warningThreshold,
            'block_threshold' => $blockThreshold,
        ];
    }

    private function schedulerHealth(\Illuminate\Support\Carbon $now): array
    {
        $latest = SchedulerRun::query()->orderByDesc('started_at')->first();
        $warnings = [];
        $blockers = [];
        $state = 'healthy';

        if (! $latest) {
            return [
                'state' => 'caution',
                'blockers' => [],
                'warnings' => ['scheduler_history_missing'],
                'last_run_at' => null,
                'last_command' => null,
                'status' => null,
            ];
        }

        $ageMinutes = $latest->finished_at
            ? $now->diffInMinutes($latest->finished_at)
            : ($latest->started_at ? $now->diffInMinutes($latest->started_at) : null);

        $warningThreshold = (int) config('ops.live_readiness.scheduler_stale_minutes', 15);
        $blockThreshold = (int) config('ops.live_readiness.scheduler_block_minutes', 30);

        if (($latest->status ?? '') === 'running' && $ageMinutes !== null && $ageMinutes >= $blockThreshold) {
            $blockers[] = 'scheduler_stalled';
            $state = 'blocked';
        } elseif ($ageMinutes !== null && $ageMinutes >= $warningThreshold) {
            $warnings[] = 'scheduler_stale';
            $state = 'degraded';
        }

        return [
            'state' => $state,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'last_run_at' => $latest->finished_at?->toIso8601String() ?: $latest->started_at?->toIso8601String(),
            'last_command' => $latest->command,
            'status' => $latest->status,
            'age_minutes' => $ageMinutes,
        ];
    }

    private function orderHealth(string $workspaceId, \Illuminate\Support\Carbon $now): array
    {
        $liveQuery = Order::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query): void {
                $query->where('meta->mode', 'live')
                    ->orWhereNotNull('integration_id')
                    ->orWhereNotNull('bot_id');
            });

        $openStatuses = [Order::STATUS_PENDING_PROVIDER, Order::STATUS_NEW, Order::STATUS_PARTIAL, Order::STATUS_UNCERTAIN];
        $openOrders = (clone $liveQuery)->whereIn('status', $openStatuses)->get();
        $uncertainOrders = $openOrders->where('status', Order::STATUS_UNCERTAIN)->count();
        $staleOrders = $openOrders->filter(function (Order $order) use ($now): bool {
            return $order->created_at && $order->created_at->lt($now->copy()->subMinutes(30));
        })->count();
        $recentFailures = (clone $liveQuery)
            ->where('status', Order::STATUS_FAILED)
            ->where('created_at', '>=', $now->copy()->subHours(24))
            ->count();
        $reconciliationMismatches = RiskEvent::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('type', ['reconciliation_mismatch', 'stale_pending_order', 'execution_uncertain'])
            ->where('triggered_at', '>=', $now->copy()->subHours(24))
            ->count();

        $warnings = [];
        $blockers = [];
        $state = 'healthy';

        $uncertainWarningThreshold = (int) config('ops.live_readiness.uncertain_order_warning', 3);
        $uncertainBlockThreshold = (int) config('ops.live_readiness.uncertain_order_block', 10);
        $failureWarningThreshold = (int) config('ops.live_readiness.order_failure_warning', 3);
        $failureBlockThreshold = (int) config('ops.live_readiness.order_failure_block', 5);

        if ($uncertainOrders >= $uncertainBlockThreshold || $reconciliationMismatches >= $uncertainBlockThreshold) {
            $blockers[] = 'order_uncertainty_blocked';
            $state = 'blocked';
        } elseif ($uncertainOrders >= $uncertainWarningThreshold || $reconciliationMismatches > 0) {
            $warnings[] = 'order_uncertainty_warning';
            $state = 'degraded';
        }

        if ($recentFailures >= $failureBlockThreshold) {
            $blockers[] = 'order_failure_streak_blocked';
            $state = 'blocked';
        } elseif ($recentFailures >= $failureWarningThreshold) {
            $warnings[] = 'order_failure_warning';
            $state = $state === 'blocked' ? 'blocked' : 'degraded';
        }

        if ($staleOrders > 0 && $state === 'healthy') {
            $warnings[] = 'stale_open_orders';
            $state = 'caution';
        }

        return [
            'state' => $state,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'open_orders' => $openOrders->count(),
            'uncertain_orders' => $uncertainOrders,
            'stale_orders' => $staleOrders,
            'order_failures_24h' => $recentFailures,
            'reconciliation_mismatches_24h' => $reconciliationMismatches,
        ];
    }

    private function aiHealth(string $workspaceId, \Illuminate\Support\Carbon $now): array
    {
        $config = AiTradingConfig::query()->where('workspace_id', $workspaceId)->first();
        if (! $config || ! (bool) $config->enabled) {
            return [
                'state' => 'healthy',
                'blockers' => [],
                'warnings' => ['ai_trading_disabled'],
                'provider' => $config?->provider ?? config('intelligence.ai_trading.provider', 'heuristic'),
                'pending_ai_decisions' => 0,
                'recent_failed_cycles' => 0,
            ];
        }

        $provider = strtolower((string) ($config->provider ?: config('intelligence.ai_trading.provider', 'heuristic')));
        $recentFailedCycles = AiDecisionCycle::query()
            ->where('workspace_id', $workspaceId)
            ->where('started_at', '>=', $now->copy()->subMinutes(30))
            ->where('status', AiDecisionCycle::STATUS_FAILED)
            ->count();
        $pendingDecisions = \App\Models\AiDecision::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('final_status', [\App\Models\AiDecision::STATUS_RECORDED, \App\Models\AiDecision::STATUS_PENDING_APPROVAL, \App\Models\AiDecision::STATUS_APPROVED])
            ->count();

        $warnings = [];
        $blockers = [];
        $state = 'healthy';
        $providerReady = $this->providerReady($provider);

        if (! $providerReady && $provider !== 'heuristic') {
            $blockers[] = 'ai_provider_unavailable';
            $state = 'blocked';
        }

        if ($recentFailedCycles >= 3) {
            $warnings[] = 'ai_provider_failures';
            $state = $state === 'blocked' ? 'blocked' : 'degraded';
        }

        if ($pendingDecisions >= 10) {
            $warnings[] = 'approval_backlog';
            $state = $state === 'blocked' ? 'blocked' : 'caution';
        }

        return [
            'state' => $state,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'provider' => $provider,
            'provider_ready' => $providerReady,
            'pending_ai_decisions' => $pendingDecisions,
            'recent_failed_cycles' => $recentFailedCycles,
        ];
    }

    private function externalContextHealth(string $workspaceId, \Illuminate\Support\Carbon $now): array
    {
        $useExternal = (bool) data_get(
            AiTradingConfig::query()->where('workspace_id', $workspaceId)->first(),
            'use_external_context',
            false
        );

        $hasSnapshotTable = Schema::hasTable('external_context_snapshots');
        $hasSourceHealthTable = Schema::hasTable('external_context_source_health');
        $latestSnapshot = $hasSnapshotTable
            ? ExternalContextSnapshot::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('computed_at')
                ->first()
            : null;
        $sourceHealth = $hasSourceHealthTable
            ? ExternalContextSourceHealth::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('updated_at')
                ->get()
            : collect();

        if (! $useExternal) {
            return [
                'state' => 'healthy',
                'blockers' => [],
                'warnings' => ['external_context_disabled'],
                'source_health' => $sourceHealth->values()->all(),
                'latest_snapshot_at' => $latestSnapshot?->computed_at?->toIso8601String(),
                'snapshot_state' => $latestSnapshot?->freshness_state,
            ];
        }

        if (! $hasSnapshotTable || ! $hasSourceHealthTable) {
            return [
                'state' => 'degraded',
                'blockers' => [],
                'warnings' => ['external_context_tables_missing'],
                'source_health' => [],
                'latest_snapshot_at' => null,
                'snapshot_state' => null,
                'age_minutes' => null,
            ];
        }

        $maxAgeMinutes = (int) config('intelligence.external_context.max_age_minutes', 60);
        $ageMinutes = $latestSnapshot?->computed_at ? $now->diffInMinutes($latestSnapshot->computed_at) : null;
        $anyDown = $sourceHealth->contains(fn ($row) => (string) ($row->status ?? '') === 'down');
        $anyDegraded = $sourceHealth->contains(fn ($row) => (string) ($row->status ?? '') === 'degraded');
        $rssSource = $sourceHealth->first(fn ($row) => (string) ($row->provider ?? '') === 'news:rss');
        $rssAlert = (string) data_get($rssSource?->meta_json, 'alert', '');

        $blockers = [];
        $warnings = [];
        $state = 'healthy';

        if (! $latestSnapshot) {
            $warnings[] = 'external_context_snapshot_missing';
            $state = 'degraded';
        } elseif ($ageMinutes !== null && $ageMinutes > $maxAgeMinutes) {
            $warnings[] = 'external_context_snapshot_stale';
            $state = 'degraded';
        }

        if ($anyDown && $latestSnapshot === null) {
            $blockers[] = 'external_context_unavailable';
            $state = 'blocked';
        }

        if ($anyDegraded) {
            $warnings[] = 'external_context_source_degraded';
            $state = $state === 'blocked' ? 'blocked' : 'caution';
        }

        if ($rssAlert === 'rss_feed_stale') {
            $warnings[] = 'external_news_feed_stale';
            $state = $state === 'blocked' ? 'blocked' : 'caution';
        }

        if ((bool) ($latestSnapshot?->pause_review_hint ?? false)) {
            $warnings[] = 'external_context_pause_review';
            $state = $state === 'blocked' ? 'blocked' : 'caution';
        }

        return [
            'state' => $state,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'source_health' => $sourceHealth->values()->map->toArray()->all(),
            'latest_snapshot_at' => $latestSnapshot?->computed_at?->toIso8601String(),
            'snapshot_state' => $latestSnapshot?->freshness_state,
            'age_minutes' => $ageMinutes,
        ];
    }

    private function incidentHealth(string $workspaceId): array
    {
        $openStatuses = ['open', 'acknowledged', 'investigating'];
        $incidents = Incident::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', $openStatuses)
            ->get();

        $critical = $incidents->where('severity', 'critical')->count();
        $high = $incidents->where('severity', 'high')->count();
        $warnings = [];
        $blockers = [];
        $state = 'healthy';

        if ($critical > 0) {
            $blockers[] = 'critical_incident_open';
            $state = 'blocked';
        } elseif ($high > 0) {
            $warnings[] = 'high_incident_open';
            $state = 'degraded';
        } elseif ($incidents->count() > 0) {
            $warnings[] = 'incident_open';
            $state = 'caution';
        }

        return [
            'state' => $state,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'open_incidents' => $incidents->count(),
            'critical_open' => $critical,
            'high_open' => $high,
            'incidents' => $incidents->map(fn (Incident $incident) => [
                'id' => $incident->id,
                'incident_key' => $incident->incident_key,
                'kind' => $incident->kind,
                'severity' => $incident->severity,
                'status' => $incident->status,
                'title' => $incident->title,
                'runbook_key' => $incident->runbook_key,
                'detected_at' => $incident->detected_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function driftHealth(string $workspaceId): array
    {
        $detections = OperationalDriftDetection::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->orderByDesc('detected_at')
            ->get();

        $critical = $detections->where('severity', 'action_required')->count();
        $warnings = [];
        $blockers = [];
        $state = 'healthy';

        if ($critical > 0) {
            $blockers[] = 'operational_drift_action_required';
            $state = 'blocked';
        } elseif ($detections->isNotEmpty()) {
            $warnings[] = 'operational_drift_detected';
            $state = 'degraded';
        }

        return [
            'state' => $state,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'open_drift_detections' => $detections->count(),
            'action_required' => $critical,
            'latest' => $detections->take(5)->map(fn (OperationalDriftDetection $detection) => [
                'id' => $detection->id,
                'metric_key' => $detection->metric_key,
                'state' => $detection->state,
                'severity' => $detection->severity,
                'summary' => $detection->summary,
                'detected_at' => $detection->detected_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function stateFromSignals(array $blockers, array $warnings, array $signals): string
    {
        if ($blockers !== [] || in_array('blocker', $signals, true)) {
            return 'blocked';
        }

        if ($warnings !== []) {
            return count($warnings) >= 3 ? 'degraded' : 'caution';
        }

        return 'healthy';
    }

    private function recommendations(string $state, array $blockers, array $warnings): array
    {
        $items = [];

        if ($state === 'blocked') {
            $items[] = 'Pause new live submissions.';
            $items[] = 'Inspect the blocker list and linked incidents.';
        } elseif ($state === 'degraded') {
            $items[] = 'Reduce new trade frequency.';
            $items[] = 'Review open order uncertainty and queue health.';
        } elseif ($state === 'caution') {
            $items[] = 'Keep the operator in review mode until warnings clear.';
        } else {
            $items[] = 'Operational health is acceptable.';
        }

        if (in_array('external_context_snapshot_stale', $warnings, true)) {
            $items[] = 'Refresh external context before re-enabling aggressive automation.';
        }

        if (in_array('ai_provider_failures', $warnings, true)) {
            $items[] = 'Keep AI decisions in advisor or manual approval mode.';
        }

        if (in_array('queue_backlog_blocked', $blockers, true)) {
            $items[] = 'Drain the queue before resuming live automation.';
        }

        return array_values(array_unique($items));
    }

    private function providerReady(string $provider): bool
    {
        return match ($provider) {
            'heuristic', 'null', 'mock' => true,
            'openai' => (bool) config('intelligence.ai_trading.openai.api_key'),
            'gemini' => (bool) config('intelligence.ai_trading.gemini.api_key'),
            'anthropic' => (bool) config('intelligence.ai_trading.anthropic.api_key'),
            default => false,
        };
    }
}
