<?php

namespace App\Domain\Ops;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\RuntimeSafetySettingsService;
use App\Models\MarketFeedStatus;
use App\Models\QuickTrade;
use App\Models\FutureTradeBotConfig;
use App\Models\RatioBotConfig;
use App\Models\RobotSession;
use App\Models\SchedulerRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TradingRuntimeHealthService
{
    public function __construct(
        private readonly MarketPriceCacheService $prices,
    ) {
    }

    public function snapshot(?string $workspaceId = null, ?string $symbol = null): array
    {
        $supervisor = $this->supervisorStatus();
        $quickSettler = $this->withSupervisorProcess(
            $this->scheduledCommand('quick-trades:settle', 90),
            $supervisor['quick_settler'] ?? 'UNKNOWN'
        );
        $quickSettler = $this->withRuntimeHeartbeat($quickSettler, 'autofinx-quick-settler-heartbeat.json', 30);
        $robotEvaluator = $this->robotEvaluatorHealth($workspaceId, $supervisor['robot_evaluator'] ?? 'UNKNOWN');
        $robotEvaluator = $this->withRuntimeHeartbeat($robotEvaluator, 'autofinx-robot-evaluator-heartbeat.json', 45);
        $futureTick = $this->futureTickHealth($workspaceId, $supervisor['tick_loop'] ?? 'UNKNOWN');
        $futureReconcile = $this->scheduledCommand('future-trade:reconcile', 900);
        $futureUserStream = $this->futureUserStreamHealth($workspaceId, $supervisor['user_stream'] ?? 'UNKNOWN');
        $futureLiveFeed = $symbol === null
            ? $this->marketFeed($workspaceId)
            : $this->selectedFuturesFeed($workspaceId, $symbol);
        // Ratio Bot's own spot feed (`market:ws-consume --market-type=spot`,
        // Supervisor program `autofinx-ratio-feed`) can go stale while
        // future_trade.live_feed above stays green off a healthy futures
        // feed alone — that gap left a real stuck spot bot with no automated
        // recovery, since RuntimeRepairCommand only ever watched
        // future_trade.live_feed. This is scoped to spot symbols only so a
        // stale futures feed alone never flips this key red.
        $ratioBotLiveFeed = $this->marketFeed($workspaceId, 'spot');
        $executionQueue = $this->queue(['execution', 'critical', 'reconcile', 'monitoring']);
        $marketQueue = $this->queue(['market', 'signals', 'strategies', 'ratio_bot', 'ops']);
        $defaultQueue = $this->queue(['analytics', 'notifications', 'default', 'monitoring']);
        $expiredQuickTrades = $this->expiredQuickTrades($workspaceId);
        $dailyLossLock = $this->dailyLossLock($workspaceId);

        $quickTrade = [
            'settler' => $quickSettler['state'],
            'settler_age_seconds' => $quickSettler['age_seconds'] ?? null,
            'settler_detail' => $quickSettler,
            'open_expired_count' => $expiredQuickTrades,
        ];
        if ($expiredQuickTrades > 0 && $quickTrade['settler'] === 'green') {
            $quickTrade['settler'] = 'yellow';
        }

        $robotTrade = [
            'evaluator' => $robotEvaluator['state'],
            'evaluator_age_seconds' => $robotEvaluator['age_seconds'] ?? null,
            'evaluator_detail' => $robotEvaluator,
            'market_feed' => $futureLiveFeed['state'],
            'market_feed_detail' => $futureLiveFeed,
        ];

        $futureTrade = [
            'live_feed' => $futureLiveFeed['state'],
            'live_feed_age_seconds' => $futureLiveFeed['age_seconds'] ?? null,
            'live_feed_detail' => $futureLiveFeed,
            'user_stream' => $futureUserStream['state'],
            'user_stream_detail' => $futureUserStream,
            'execution_queue' => $executionQueue['state'],
            'execution_queue_detail' => $executionQueue,
            'market_queue' => $marketQueue['state'],
            'market_queue_detail' => $marketQueue,
            'default_queue' => $defaultQueue['state'],
            'default_queue_detail' => $defaultQueue,
            'tick' => $futureTick['state'],
            'tick_detail' => $futureTick,
            'reconcile' => $futureReconcile['state'],
            'reconcile_detail' => $futureReconcile,
            'daily_loss_lock' => $dailyLossLock,
        ];

        $ratioBot = [
            'live_feed' => $ratioBotLiveFeed['state'],
            'live_feed_age_seconds' => $ratioBotLiveFeed['age_seconds'] ?? null,
            'live_feed_detail' => $ratioBotLiveFeed,
        ];

        $binance = [
            'rest' => 'yellow',
            'ws' => $futureLiveFeed['state'],
            'circuit' => 'unknown',
            'message' => 'Runtime health does not perform a signed Binance probe; feature-level live readiness still validates credentials.',
        ];

        $reasons = [];
        $this->collectReasons('quick_trade.settler', $quickTrade['settler'], $quickSettler, $reasons);
        if ($expiredQuickTrades > 0) {
            $reasons[] = 'quick_trade_expired_open_trades';
        }
        $this->collectReasons('robot_trade.evaluator', $robotTrade['evaluator'], $robotEvaluator, $reasons);
        $this->collectReasons('future_trade.live_feed', $futureTrade['live_feed'], $futureLiveFeed, $reasons);
        $this->collectReasons('future_trade.user_stream', $futureTrade['user_stream'], $futureUserStream, $reasons);
        $this->collectReasons('future_trade.execution_queue', $futureTrade['execution_queue'], $executionQueue, $reasons);
        $this->collectReasons('future_trade.tick', $futureTrade['tick'], $futureTick, $reasons);
        // Deliberately NOT folded into `reasons`/`overall`/liveAllowed(): those
        // gate QuickTrade/Robot Trade/Future Trade live entries too (see
        // QuickTradeService::place()'s require_green_for_live check), and a
        // stale Ratio Bot spot feed has no bearing on those other engines'
        // readiness. This key exists so RuntimeRepairCommand can react to it
        // independently — see that command for the actual repair trigger.

        $overall = $this->worst([
            $quickTrade['settler'],
            $robotTrade['evaluator'],
            $robotTrade['market_feed'],
            $futureTrade['live_feed'],
            $futureTrade['user_stream'],
            $futureTrade['execution_queue'],
            $futureTrade['tick'],
        ]);

        return [
            'overall' => $overall,
            'workspace_id' => $workspaceId,
            'generated_at' => now()->toIso8601String(),
            'quick_trade' => $quickTrade,
            'robot_trade' => $robotTrade,
            'future_trade' => $futureTrade,
            'ratio_bot' => $ratioBot,
            'binance' => $binance,
            'supervisor' => $supervisor,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function selectedFuturesFeed(?string $workspaceId, string $symbol): array
    {
        $symbol = strtoupper(str_replace(['/', '-', ' '], '', trim($symbol)));
        $maxAge = app(RuntimeSafetySettingsService::class)->liveFeedMaxAgeSeconds($workspaceId);
        $feed = $this->prices->getLiveFeedSnapshot('binance_futures', $symbol, $maxAge);
        $fresh = ($feed['fresh'] ?? false) === true && (float) ($feed['price'] ?? 0) > 0;

        // A different symbol's heartbeat cannot establish this order's freshness.
        return array_merge($feed ?? [], [
            'state' => $fresh ? 'green' : 'red',
            'reason' => $fresh ? 'ok_redis_live_feed' : 'market_feed_stale_or_missing_required_symbol',
            'symbol' => $symbol,
            'exchange' => 'binance_futures',
            'market_type' => 'futures',
            'age_seconds' => $feed['age_seconds'] ?? null,
            'max_age_seconds' => $maxAge,
            'truth_source' => 'websocket_redis',
            'selected_symbol_only' => true,
        ]);
    }

    public function liveAllowed(?string $workspaceId = null): array
    {
        $snapshot = $this->snapshot($workspaceId);
        $allowed = ($snapshot['overall'] ?? 'red') !== 'red';

        return [
            'allowed' => $allowed,
            'reason' => $allowed ? null : 'runtime_health_red',
            'human_message' => $allowed
                ? 'Runtime health is acceptable for live trading.'
                : 'Live trading is blocked because required runtime workers or feeds are unhealthy.',
            'health' => $snapshot,
        ];
    }

    private function scheduledCommand(string $command, int $maxAgeSeconds): array
    {
        if (! Schema::hasTable('scheduler_runs')) {
            return ['state' => 'yellow', 'reason' => 'scheduler_runs_table_missing'];
        }

        $run = SchedulerRun::query()
            ->where('command', $command)
            ->orderByDesc('started_at')
            ->first();

        if (! $run) {
            $supervisorRuntime = (bool) config('autofinx.runtime.supervisor', false);
            return [
                'state' => $supervisorRuntime ? 'yellow' : 'red',
                'reason' => 'scheduler_command_never_seen',
                'command' => $command,
                'supervisor_runtime' => $supervisorRuntime,
            ];
        }

        $lastAt = $run->finished_at ?: $run->started_at;
        $age = $lastAt ? $lastAt->diffInSeconds(now(), true) : null;
        $state = 'green';
        $reason = 'ok';
        if (($run->status ?? '') === 'running' && $age !== null && $age > ($maxAgeSeconds * 2)) {
            $state = 'red';
            $reason = 'scheduler_command_stalled';
        } elseif ($age === null || $age > $maxAgeSeconds) {
            $state = 'yellow';
            $reason = 'scheduler_command_stale';
        }

        return [
            'state' => $state,
            'reason' => $reason,
            'command' => $command,
            'last_run_at' => $lastAt?->toIso8601String(),
            'age_seconds' => $age,
            'status' => $run->status,
            'max_age_seconds' => $maxAgeSeconds,
        ];
    }

    private function robotEvaluatorHealth(?string $workspaceId, string $supervisorStatus): array
    {
        $base = $this->withSupervisorProcess(
            $this->scheduledCommand('robot-sessions:evaluate', 90),
            $supervisorStatus
        );
        $running = Schema::hasTable('robot_sessions')
            ? RobotSession::query()
                ->where('status', 'RUNNING')
                ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
                ->count()
            : 0;

        if ((bool) config('autofinx.runtime.supervisor', false) && $running === 0 && ($base['state'] ?? '') === 'red') {
            $base['state'] = $supervisorStatus === 'RUNNING' ? 'green' : 'yellow';
            $base['reason'] = 'no_running_robot_sessions_scheduler_history_missing';
        }

        if ($running === 0 && ($base['state'] ?? '') === 'yellow') {
            $base['reason'] = 'no_running_robot_sessions';
        }

        $base['running_robot_sessions'] = $running;

        return $base;
    }

    private function futureTickHealth(?string $workspaceId, string $supervisorStatus): array
    {
        $base = $this->withSupervisorProcess(
            $this->scheduledCommand('future-trade:tick', 90),
            $supervisorStatus
        );
        $enabled = $this->liveOrEnabledFutureConfigs($workspaceId);

        if ((bool) config('autofinx.runtime.supervisor', false) && $enabled === 0 && in_array(($base['state'] ?? ''), ['red', 'yellow'], true)) {
            $base['state'] = 'yellow';
            $base['reason'] = 'no_enabled_future_trade_configs';
        }

        $base['enabled_future_trade_configs'] = $enabled;

        return $base;
    }

    private function futureUserStreamHealth(?string $workspaceId, string $supervisorStatus): array
    {
        $liveConfigs = Schema::hasTable('future_trade_bot_configs')
            ? FutureTradeBotConfig::query()
                ->where('enable_live_trading', true)
                ->where('slot_index', 0)
                ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
                ->count()
            : 0;

        if ($liveConfigs === 0) {
            return [
                'state' => 'green',
                'reason' => 'no_live_enabled_future_trade_configs',
                'command' => 'future-trade:user-stream',
                'live_enabled_future_trade_configs' => 0,
            ];
        }

        $base = $this->withSupervisorProcess(
            $this->scheduledCommand('future-trade:user-stream', (int) config('autofinx.runtime.user_stream_max_age_seconds', 60) + 30),
            $supervisorStatus
        );
        $base['live_enabled_future_trade_configs'] = $liveConfigs;

        return $base;
    }

    private function withSupervisorProcess(array $health, string $supervisorStatus): array
    {
        $health['supervisor_status'] = $supervisorStatus;

        if ($supervisorStatus !== 'RUNNING') {
            return $health;
        }

        if (($health['state'] ?? 'red') !== 'green') {
            $health['state'] = 'green';
            $health['reason'] = 'supervisor_process_running';
        }

        return $health;
    }

    private function withRuntimeHeartbeat(array $health, string $filename, int $maxAgeSeconds): array
    {
        $heartbeat = $this->runtimeHeartbeat($filename, $maxAgeSeconds);
        $health['heartbeat_detail'] = $heartbeat;

        if (($heartbeat['state'] ?? 'red') === 'green') {
            $health['state'] = 'green';
            $health['reason'] = 'runtime_heartbeat_fresh';
            $health['age_seconds'] = $heartbeat['age_seconds'] ?? ($health['age_seconds'] ?? null);
        }

        return $health;
    }

    private function runtimeHeartbeat(string $filename, int $maxAgeSeconds): array
    {
        $path = storage_path('framework/'.$filename);
        if (! file_exists($path)) {
            return [
                'state' => 'red',
                'reason' => 'heartbeat_file_missing',
                'path' => $path,
                'max_age_seconds' => $maxAgeSeconds,
            ];
        }

        $raw = @file_get_contents($path);
        $data = $raw !== false ? @json_decode($raw, true) : null;
        $epoch = is_array($data) ? (int) ($data['epoch'] ?? 0) : 0;
        $age = $epoch > 0 ? max(0, time() - $epoch) : null;

        if ($age === null) {
            return [
                'state' => 'red',
                'reason' => 'heartbeat_file_invalid',
                'path' => $path,
                'max_age_seconds' => $maxAgeSeconds,
            ];
        }

        $state = $age <= $maxAgeSeconds ? 'green' : ($age <= ($maxAgeSeconds * 3) ? 'yellow' : 'red');

        return [
            'state' => $state,
            'reason' => $state === 'green' ? 'ok' : 'heartbeat_stale',
            'path' => $path,
            'age_seconds' => $age,
            'max_age_seconds' => $maxAgeSeconds,
            'last_tick_at' => (string) ($data['at'] ?? ''),
            'pid' => (int) ($data['pid'] ?? 0),
        ];
    }

    private function liveOrEnabledFutureConfigs(?string $workspaceId): int
    {
        if (! Schema::hasTable('future_trade_bot_configs')) {
            return 0;
        }

        return FutureTradeBotConfig::query()
            ->where('slot_index', 0)
            ->where(function ($query): void {
                $query->where('enabled', true)->orWhere('enable_live_trading', true);
            })
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->count();
    }

    /**
     * @param 'all'|'spot'|'futures' $scope Restricts which required symbols
     *   (and which exchange's DB/heartbeat fallback) this check considers.
     *   'all' is future_trade.live_feed's existing behavior (spot + futures
     *   combined, unchanged); 'spot' is ratio_bot.live_feed — Ratio Bot's own
     *   spot feed (`market:ws-consume --market-type=spot`) can go stale while
     *   the shared 'all' check stays green off a healthy futures feed alone,
     *   which is exactly the gap that left a stuck spot bot with no
     *   automated recovery (see RuntimeRepairCommand).
     */
    private function marketFeed(?string $workspaceId, string $scope = 'all'): array
    {
        $maxAge = app(RuntimeSafetySettingsService::class)->liveFeedMaxAgeSeconds($workspaceId);
        $symbols = $this->monitoredMarketSymbols($workspaceId);
        $symbols = match ($scope) {
            'spot' => ['spot' => $symbols['spot'], 'futures' => []],
            'futures' => ['spot' => [], 'futures' => $symbols['futures']],
            default => $symbols,
        };
        $requiredFeeds = $this->requiredFeedSnapshots($symbols, $maxAge);

        if ($requiredFeeds === []) {
            return [
                'state' => 'green',
                'reason' => 'no_enabled_feed_dependent_bots',
                'age_seconds' => null,
                'max_age_seconds' => $maxAge,
                'symbols' => $symbols,
                'truth_source' => 'not_required',
            ];
        }

        $missingOrStale = array_values(array_filter(
            $requiredFeeds,
            static fn (array $snapshot): bool => ($snapshot['fresh'] ?? false) !== true
        ));
        $redisFeed = $this->freshestSnapshot(array_values(array_filter(
            array_map(static fn (array $snapshot): ?array => $snapshot['snapshot'] ?? null, $requiredFeeds)
        )));

        if ($missingOrStale === [] && ($redisFeed['fresh'] ?? false) === true) {
            return [
                'state' => 'green',
                'reason' => 'ok_redis_live_feed',
                'last_tick_at' => (string) ($redisFeed['received_at'] ?? ''),
                'age_seconds' => $redisFeed['age_seconds'],
                'max_age_seconds' => $maxAge,
                'symbol' => (string) ($redisFeed['symbol'] ?? ''),
                'price' => (float) ($redisFeed['price'] ?? 0),
                'source' => 'redis_live_feed',
                'stream' => (string) ($redisFeed['stream'] ?? ''),
                'event_type' => (string) ($redisFeed['event_type'] ?? ''),
                'event_lag_ms' => (int) ($redisFeed['event_lag_ms'] ?? 0),
                'truth_source' => 'websocket_redis',
                'db_is_snapshot_only' => true,
                'required_feeds' => $requiredFeeds,
            ];
        }

        $exchangesForScope = match ($scope) {
            'spot' => ['binance'],
            'futures' => ['binance_futures'],
            default => ['binance', 'binance_futures'],
        };

        if (! Schema::hasTable('market_feed_status')) {
            return $this->demoteForMissingRequiredFeeds($this->heartbeatFeed($redisFeed, $scope, $requiredFeeds), $missingOrStale, $requiredFeeds);
        }

        $query = MarketFeedStatus::query()->whereNotNull('last_tick_at');
        if ($workspaceId && Schema::hasColumn('market_feed_status', 'workspace_id')) {
            $query->where('workspace_id', $workspaceId);
        }
        if (Schema::hasColumn('market_feed_status', 'exchange')) {
            $query->whereIn('exchange', $exchangesForScope);
        }

        $row = $query->orderByDesc('last_tick_at')->first();
        if (! $row) {
            return $this->demoteForMissingRequiredFeeds($this->heartbeatFeed($redisFeed, $scope, $requiredFeeds), $missingOrStale, $requiredFeeds);
        }

        $age = $row->last_tick_at->diffInSeconds(now(), true);
        $heartbeat = $this->heartbeatFeed($redisFeed, $scope, $requiredFeeds);
        if (
            ($heartbeat['state'] ?? 'red') === 'green'
            && isset($heartbeat['age_seconds'])
            && (int) $heartbeat['age_seconds'] < $age
        ) {
            return $this->demoteForMissingRequiredFeeds(array_merge($heartbeat, [
                'reason' => 'ok_heartbeat_fallback_db_tick_stale',
                'db_last_tick_at' => $row->last_tick_at->toIso8601String(),
                'db_age_seconds' => $age,
                'db_symbol' => (string) ($row->symbol ?? ''),
                'db_source' => (string) ($row->source ?? ''),
            ]), $missingOrStale, $requiredFeeds);
        }

        $state = $age <= $maxAge ? 'green' : ($age <= ($maxAge * 3) ? 'yellow' : 'red');
        if ($missingOrStale !== [] && $state === 'green') {
            $state = 'red';
        }

        return [
            'state' => $state,
            'reason' => $state === 'green' ? 'ok' : 'market_feed_stale_or_missing_required_symbol',
            'last_tick_at' => $row->last_tick_at->toIso8601String(),
            'age_seconds' => $age,
            'max_age_seconds' => $maxAge,
            'symbol' => (string) ($row->symbol ?? ''),
            'price' => (float) ($row->last_price ?? 0),
            'source' => (string) ($row->source ?? ''),
            'truth_source' => 'db_snapshot_fallback',
            'required_feeds' => $requiredFeeds,
            'missing_or_stale_feeds' => $missingOrStale,
        ];
    }

    /**
     * @param array{spot: string[], futures: string[]} $symbols
     * @return array<int, array<string, mixed>>
     */
    private function requiredFeedSnapshots(array $symbols, int $maxAge): array
    {
        $feeds = [];
        foreach (['binance' => $symbols['spot'], 'binance_futures' => $symbols['futures']] as $exchange => $marketSymbols) {
            foreach ($marketSymbols as $symbol) {
                $snapshot = $this->prices->getLiveFeedSnapshot($exchange, (string) $symbol, $maxAge);
                $feeds[] = [
                    'exchange' => $exchange,
                    'market_type' => $exchange === 'binance_futures' ? 'futures' : 'spot',
                    'symbol' => strtoupper((string) $symbol),
                    'fresh' => (bool) ($snapshot['fresh'] ?? false),
                    'age_seconds' => $snapshot['age_seconds'] ?? null,
                    'received_at' => $snapshot['received_at'] ?? null,
                    'source_key' => $snapshot['source_key'] ?? null,
                    'snapshot' => $snapshot,
                ];
            }
        }

        return $feeds;
    }

    /**
     * The heartbeat file only proves a websocket process is alive and its
     * MOST RECENT tick's symbol/age — it says nothing about whether every
     * symbol this workspace's bots actually require is fresh (e.g. a
     * futures-only heartbeat ticking BTCUSDT tells us nothing about a
     * required ETHUSDT feed that's genuinely stale). Without this, both
     * heartbeat-fallback branches above would report 'green' purely because
     * SOME symbol is alive, silently masking a required symbol that
     * requiredFeedSnapshots() already proved is missing or stale — the same
     * safety check the plain DB-snapshot path below already applies.
     *
     * @param array<int, array<string, mixed>> $missingOrStale
     * @param array<int, array<string, mixed>> $requiredFeeds
     */
    private function demoteForMissingRequiredFeeds(array $health, array $missingOrStale, array $requiredFeeds): array
    {
        if ($missingOrStale === [] || ($health['state'] ?? 'red') !== 'green') {
            return $health;
        }

        $health['state'] = 'red';
        $health['reason'] = 'market_feed_stale_or_missing_required_symbol';
        $health['required_feeds'] = $requiredFeeds;
        $health['missing_or_stale_feeds'] = $missingOrStale;

        return $health;
    }

    /**
     * @param 'all'|'spot'|'futures' $scope
     * @param array<int, array<string, mixed>> $requiredFeeds Same shape as
     *   requiredFeedSnapshots()'s return — used to prefer the specific
     *   required symbol's own per_symbol[SYMBOL] heartbeat entry over the
     *   file's top-level aggregate fields below.
     */
    private function heartbeatFeed(?array $redisFeed = null, string $scope = 'all', array $requiredFeeds = []): array
    {
        $paths = match ($scope) {
            'spot' => [storage_path('framework/autofinx-market-ws-heartbeat-spot.json')],
            'futures' => [storage_path('framework/autofinx-market-ws-heartbeat-futures.json')],
            default => [
                storage_path('framework/autofinx-market-ws-heartbeat-spot.json'),
                storage_path('framework/autofinx-market-ws-heartbeat-futures.json'),
            ],
        };
        $heartbeat = $this->freshestHeartbeat($paths);

        $path = $heartbeat['path'] ?? $paths[0];
        $data = $heartbeat['data'] ?? null;
        if (! file_exists($path)) {
            return [
                'state' => 'red',
                'reason' => 'no_market_feed_tick',
                'redis_feed' => $redisFeed,
                'truth_source' => 'websocket_redis_missing',
            ];
        }

        $maxAge = app(RuntimeSafetySettingsService::class)->liveFeedMaxAgeSeconds(null);

        // The file's top-level last_message_epoch/symbol only prove SOME
        // stream on this daemon ticked recently — it may be an on-demand
        // chart symbol, a RobotSession symbol, or any other subscription,
        // not necessarily one this workspace's bots actually require. Prefer
        // the required symbol's own per_symbol[SYMBOL] entry (written by
        // MarketWsConsumerCommand alongside the aggregate fields) whenever
        // one is available, so a healthy unrelated symbol can never make a
        // genuinely stale required symbol look fresh in this fallback path,
        // and vice versa.
        $perSymbolMatch = $this->matchedRequiredSymbolHeartbeat($data, $scope, $requiredFeeds);
        if ($perSymbolMatch !== null) {
            [$matchedSymbol, $entry] = $perSymbolMatch;
            $epoch = (int) ($entry['last_message_epoch'] ?? 0);
            $age = $epoch > 0 ? max(0, time() - $epoch) : null;

            if ($age === null) {
                return ['state' => 'red', 'reason' => 'live_feed_heartbeat_invalid'];
            }

            $state = $age <= $maxAge ? 'green' : ($age <= ($maxAge * 3) ? 'yellow' : 'red');

            return [
                'state' => $state,
                'reason' => $state === 'green' ? 'ok_heartbeat_fallback_per_symbol' : 'live_feed_heartbeat_stale',
                'last_tick_at' => isset($entry['last_message_at']) ? (string) $entry['last_message_at'] : null,
                'age_seconds' => $age,
                'max_age_seconds' => $maxAge,
                'symbol' => $matchedSymbol,
                'source' => 'heartbeat_file',
                'market_type' => (string) ($data['market_type'] ?? ''),
                'heartbeat_path' => $path,
                'message_count' => (int) ($data['message_count'] ?? 0),
                'reconnect_count' => (int) ($data['reconnect_count'] ?? 0),
                'workspace_scoped' => false,
                'redis_feed' => $redisFeed,
                'truth_source' => 'heartbeat_file_per_symbol_fallback',
            ];
        }

        // No per-symbol match (older heartbeat file without per_symbol data,
        // wrong-scope file, or none of the required symbols have ticked on
        // this daemon yet) — fall back to the file's own top-level
        // aggregate, exactly as before this fix.
        $epoch = is_array($data) ? (int) ($data['last_message_epoch'] ?? 0) : 0;
        $age = $epoch > 0 ? max(0, time() - $epoch) : null;

        if ($age === null) {
            return ['state' => 'red', 'reason' => 'live_feed_heartbeat_invalid'];
        }

        $state = $age <= $maxAge ? 'green' : ($age <= ($maxAge * 3) ? 'yellow' : 'red');

        return [
            'state' => $state,
            'reason' => $state === 'green' ? 'ok_heartbeat_fallback' : 'live_feed_heartbeat_stale',
            'last_tick_at' => isset($data['last_message_at']) ? (string) $data['last_message_at'] : null,
            'age_seconds' => $age,
            'max_age_seconds' => $maxAge,
            'symbol' => (string) ($data['symbol'] ?? ''),
            'source' => 'heartbeat_file',
            'market_type' => (string) ($data['market_type'] ?? ''),
            'heartbeat_path' => $path,
            'message_count' => (int) ($data['message_count'] ?? 0),
            'reconnect_count' => (int) ($data['reconnect_count'] ?? 0),
            'workspace_scoped' => false,
            'redis_feed' => $redisFeed,
            'truth_source' => 'heartbeat_file_fallback',
        ];
    }

    /**
     * @param 'all'|'spot'|'futures' $scope
     * @param array<int, array<string, mixed>> $requiredFeeds
     * @return array{0: string, 1: array<string, mixed>}|null [symbol, per_symbol entry] of the freshest required-symbol match, or null if none.
     */
    private function matchedRequiredSymbolHeartbeat(mixed $data, string $scope, array $requiredFeeds): ?array
    {
        if ($requiredFeeds === [] || ! is_array($data)) {
            return null;
        }

        $perSymbol = is_array($data['per_symbol'] ?? null) ? $data['per_symbol'] : [];
        if ($perSymbol === []) {
            return null;
        }

        // This one heartbeat file belongs to a single market type (its own
        // consumer process); only match required symbols whose market type
        // agrees — a spot-required symbol can never be satisfied by the
        // futures daemon's per_symbol map or vice versa. When scope is
        // already 'spot'/'futures' this is redundant with $requiredFeeds
        // already being pre-filtered by marketFeed(), but stays defensive
        // for the shared 'all' scope where freshestHeartbeat() picked
        // whichever single file (spot or futures) had the newer aggregate
        // tick.
        $fileMarketType = strtolower((string) ($data['market_type'] ?? ''));

        $best = null;
        $bestSymbol = null;
        foreach ($requiredFeeds as $feed) {
            $feedMarketType = strtolower((string) ($feed['market_type'] ?? ''));
            if ($scope === 'all' && $fileMarketType !== '' && $feedMarketType !== '' && $feedMarketType !== $fileMarketType) {
                continue;
            }

            $symbol = strtoupper(trim((string) ($feed['symbol'] ?? '')));
            if ($symbol === '' || ! is_array($perSymbol[$symbol] ?? null)) {
                continue;
            }

            $entry = $perSymbol[$symbol];
            $epoch = (int) ($entry['last_message_epoch'] ?? 0);
            if ($epoch <= 0) {
                continue;
            }

            if ($best === null || $epoch > (int) ($best['last_message_epoch'] ?? 0)) {
                $best = $entry;
                $bestSymbol = $symbol;
            }
        }

        return $best !== null && $bestSymbol !== null ? [$bestSymbol, $best] : null;
    }

    /**
     * @return array{spot: string[], futures: string[]}
     */
    private function monitoredMarketSymbols(?string $workspaceId): array
    {
        $spot = [];
        $futures = [];

        if (Schema::hasTable('ratio_bot_configs')) {
            RatioBotConfig::query()
                ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
                ->where('enabled', true)
                ->get(['symbol', 'market_type'])
                ->each(function (RatioBotConfig $config) use (&$spot, &$futures): void {
                    $symbol = strtoupper(trim((string) $config->symbol));
                    if ($symbol === '') {
                        return;
                    }

                    if ($config->marketType() === 'futures') {
                        $futures[] = $symbol;
                    } else {
                        $spot[] = $symbol;
                    }
                });
        }

        if (Schema::hasTable('future_trade_bot_configs')) {
            $futureTradeSymbols = FutureTradeBotConfig::query()
                ->with('state')
                ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
                ->get()
                ->flatMap(function (FutureTradeBotConfig $config): array {
                    $settings = $config->mergedSettings();
                    $configured = array_map(
                        static fn ($symbol) => strtoupper(trim((string) $symbol)),
                        (array) ($settings['symbols'] ?? config('future_trade.defaults.symbols', []))
                    );
                    $current = strtoupper(trim((string) ($config->state?->current_symbol ?? '')));

                    return array_values(array_filter([...$configured, $current]));
                })
                ->all();
            $futures = [...$futures, ...$futureTradeSymbols];
        }

        if (Schema::hasTable('robot_sessions')) {
            $robotSymbols = RobotSession::query()
                ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
                ->where('provider_key', 'binance')
                ->pluck('symbol')
                ->map(static fn ($symbol) => strtoupper(trim((string) $symbol)))
                ->all();
            $spot = [...$spot, ...$robotSymbols];
        }

        return [
            'spot' => collect($spot)->filter()->unique()->values()->all(),
            'futures' => collect($futures)->filter()->unique()->values()->all(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $snapshots
     */
    private function freshestSnapshot(array $snapshots): ?array
    {
        $freshest = null;

        foreach ($snapshots as $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }

            if (! $freshest || (int) ($snapshot['received_at_ms'] ?? 0) > (int) ($freshest['received_at_ms'] ?? 0)) {
                $freshest = $snapshot;
            }
        }

        return $freshest;
    }

    /**
     * @param string[] $paths
     * @return array{path?: string, data?: array<string, mixed>}
     */
    private function freshestHeartbeat(array $paths): array
    {
        $freshest = [];
        $freshestEpoch = 0;

        foreach ($paths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $raw = @file_get_contents($path);
            $data = $raw !== false ? @json_decode($raw, true) : null;
            if (! is_array($data)) {
                continue;
            }

            $epoch = (int) ($data['last_message_epoch'] ?? 0);
            if ($epoch > $freshestEpoch) {
                $freshestEpoch = $epoch;
                $freshest = ['path' => $path, 'data' => $data];
            }
        }

        return $freshest;
    }

    private function queue(array $queues): array
    {
        $pending = 0;
        $failed = 0;
        $oldestAge = null;

        if (Schema::hasTable('jobs')) {
            $pending = DB::table('jobs')->whereIn('queue', $queues)->count();
            $oldest = DB::table('jobs')->whereIn('queue', $queues)->min('created_at');
            if ($oldest) {
                $oldestAge = max(0, time() - (int) $oldest);
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->whereIn('queue', $queues)->count();
        }

        $maxLag = (int) config('autofinx.runtime.queue_max_lag_seconds', 30);
        $state = 'green';
        $reason = 'ok';
        if ($failed > 0 || ($oldestAge !== null && $oldestAge > ($maxLag * 4))) {
            $state = 'red';
            $reason = $failed > 0 ? 'failed_jobs_present' : 'queue_lag_red';
        } elseif ($pending > 0 || ($oldestAge !== null && $oldestAge > $maxLag)) {
            $state = 'yellow';
            $reason = 'queue_has_pending_or_lag';
        }

        return [
            'state' => $state,
            'reason' => $reason,
            'queues' => $queues,
            'pending' => $pending,
            'failed' => $failed,
            'oldest_pending_age_seconds' => $oldestAge,
            'max_lag_seconds' => $maxLag,
        ];
    }

    private function expiredQuickTrades(?string $workspaceId): int
    {
        if (! Schema::hasTable('quick_trades')) {
            return 0;
        }

        return QuickTrade::query()
            ->where('status', 'open')
            ->where('expires_at', '<', now()->subSeconds(15))
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->count();
    }

    private function dailyLossLock(?string $workspaceId): bool
    {
        if (! Schema::hasTable('future_trade_bot_configs')) {
            return false;
        }

        $configs = FutureTradeBotConfig::query()
            ->with('state')
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->limit(50)
            ->get();

        return $configs->contains(function (FutureTradeBotConfig $config): bool {
            $state = $config->state;
            if (! $state) {
                return false;
            }

            if (strtoupper((string) ($state->state ?? '')) === 'DAILY_LOSS_LOCKED') {
                return true;
            }

            $stopUntil = $state->daily_stop_until ?? null;
            if (! $stopUntil) {
                return false;
            }

            return method_exists($stopUntil, 'isFuture')
                ? $stopUntil->isFuture()
                : Carbon::parse((string) $stopUntil)->isFuture();
        });
    }

    /**
     * Supervisor is optional in local/test environments, so failure to inspect it
     * returns UNKNOWN instead of making runtime health itself crash.
     *
     * @return array<string, string>
     */
    private function supervisorStatus(): array
    {
        $defaults = [
            'quick_settler' => 'UNKNOWN',
            'robot_evaluator' => 'UNKNOWN',
            'live_feed' => 'UNKNOWN',
            'user_stream' => 'UNKNOWN',
            'tick_loop' => 'UNKNOWN',
            'queue_critical' => 'UNKNOWN',
        ];

        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('shell_exec')) {
            return $defaults;
        }

        $output = @shell_exec('supervisorctl status 2>/dev/null');
        if (! is_string($output) || trim($output) === '') {
            return $defaults;
        }

        $programs = $this->parseSupervisorOutput($output);

        return [
            'quick_settler' => $this->supervisorProgram($programs, ['autofinx-quick-settler', 'autofinx-trading:quick-settler']),
            'robot_evaluator' => $this->supervisorProgram($programs, ['autofinx-robot-evaluator', 'autofinx-trading:robot-evaluator']),
            'live_feed' => $this->supervisorProgram($programs, ['autofinx-trading:live-feed', 'autofinx-live-feed']),
            'user_stream' => $this->supervisorProgram($programs, ['autofinx-trading:user-stream-loop', 'autofinx-user-stream-loop']),
            'tick_loop' => $this->supervisorProgram($programs, ['autofinx-trading:tick-loop', 'autofinx-tick-loop']),
            'queue_critical' => $this->supervisorProgram($programs, ['autofinx-trading:queue-critical', 'autofinx-queue-critical']),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseSupervisorOutput(string $output): array
    {
        $programs = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (! preg_match('/^(\S+)\s+([A-Z_]+)/', trim($line), $matches)) {
                continue;
            }

            $programs[$matches[1]] = $matches[2];
        }

        return $programs;
    }

    /**
     * @param array<string, string> $programs
     * @param array<int, string> $names
     */
    private function supervisorProgram(array $programs, array $names): string
    {
        foreach ($names as $name) {
            if (isset($programs[$name])) {
                return $programs[$name];
            }
        }

        return 'UNKNOWN';
    }

    private function collectReasons(string $prefix, string $state, array $detail, array &$reasons): void
    {
        if ($state === 'green') {
            return;
        }

        $reason = (string) ($detail['reason'] ?? 'unhealthy');
        $reasons[] = $prefix.':'.$reason;
    }

    private function worst(array $states): string
    {
        if (in_array('red', $states, true)) {
            return 'red';
        }
        if (in_array('yellow', $states, true)) {
            return 'yellow';
        }

        return 'green';
    }
}
