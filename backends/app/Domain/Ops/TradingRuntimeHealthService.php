<?php

namespace App\Domain\Ops;

use App\Models\MarketFeedStatus;
use App\Models\QuickTrade;
use App\Models\FutureTradeBotConfig;
use App\Models\RobotSession;
use App\Models\SchedulerRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TradingRuntimeHealthService
{
    public function snapshot(?string $workspaceId = null): array
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
        $futureLiveFeed = $this->marketFeed($workspaceId);
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
            'binance' => $binance,
            'supervisor' => $supervisor,
            'reasons' => array_values(array_unique($reasons)),
        ];
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

    private function marketFeed(?string $workspaceId): array
    {
        if (! Schema::hasTable('market_feed_status')) {
            return ['state' => 'red', 'reason' => 'market_feed_status_table_missing'];
        }

        $query = MarketFeedStatus::query()->whereNotNull('last_tick_at');
        if ($workspaceId && Schema::hasColumn('market_feed_status', 'workspace_id')) {
            $query->where('workspace_id', $workspaceId);
        }
        if (Schema::hasColumn('market_feed_status', 'exchange')) {
            $query->whereIn('exchange', ['binance', 'binance_futures']);
        }
        if (Schema::hasColumn('market_feed_status', 'market_type')) {
            $query->where('market_type', 'futures');
        }

        $row = $query->orderByDesc('last_tick_at')->first();
        if (! $row) {
            return $this->heartbeatFeed();
        }

        $maxAge = (int) config('autofinx.runtime.live_feed_max_age_seconds', 15);
        $age = $row->last_tick_at->diffInSeconds(now(), true);
        $state = $age <= $maxAge ? 'green' : ($age <= ($maxAge * 3) ? 'yellow' : 'red');

        return [
            'state' => $state,
            'reason' => $state === 'green' ? 'ok' : 'market_feed_stale',
            'last_tick_at' => $row->last_tick_at->toIso8601String(),
            'age_seconds' => $age,
            'max_age_seconds' => $maxAge,
            'symbol' => (string) ($row->symbol ?? ''),
            'price' => (float) ($row->last_price ?? 0),
            'source' => (string) ($row->source ?? ''),
        ];
    }

    private function heartbeatFeed(): array
    {
        $path = storage_path('framework/autofinx-live-feed-heartbeat.json');
        if (! file_exists($path)) {
            return ['state' => 'red', 'reason' => 'no_futures_market_feed_tick'];
        }

        $raw = @file_get_contents($path);
        $data = $raw !== false ? @json_decode($raw, true) : null;
        $epoch = is_array($data) ? (int) ($data['last_message_epoch'] ?? 0) : 0;
        $age = $epoch > 0 ? max(0, time() - $epoch) : null;
        $maxAge = (int) config('autofinx.runtime.live_feed_max_age_seconds', 15);

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
            'message_count' => (int) ($data['message_count'] ?? 0),
            'reconnect_count' => (int) ($data['reconnect_count'] ?? 0),
            'workspace_scoped' => false,
        ];
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
