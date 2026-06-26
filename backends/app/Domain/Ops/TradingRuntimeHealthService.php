<?php

namespace App\Domain\Ops;

use App\Models\MarketFeedStatus;
use App\Models\QuickTrade;
use App\Models\SchedulerRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TradingRuntimeHealthService
{
    public function snapshot(?string $workspaceId = null): array
    {
        $quickSettler = $this->scheduledCommand('quick-trades:settle', 90);
        $robotEvaluator = $this->scheduledCommand('robot-sessions:evaluate', 90);
        $futureTick = $this->scheduledCommand('future-trade:tick', 90);
        $futureReconcile = $this->scheduledCommand('future-trade:reconcile', 900);
        $futureUserStream = $this->scheduledCommand('future-trade:user-stream', (int) config('autofinx.runtime.user_stream_max_age_seconds', 60) + 30);
        $futureLiveFeed = $this->marketFeed($workspaceId);
        $executionQueue = $this->queue(['execution', 'critical', 'reconcile', 'monitoring']);
        $marketQueue = $this->queue(['market', 'signals', 'strategies', 'ratio_bot', 'ops']);
        $defaultQueue = $this->queue(['analytics', 'notifications', 'default', 'monitoring']);
        $expiredQuickTrades = $this->expiredQuickTrades($workspaceId);

        $quickTrade = [
            'settler' => $quickSettler['state'],
            'settler_detail' => $quickSettler,
            'open_expired_count' => $expiredQuickTrades,
        ];
        if ($expiredQuickTrades > 0 && $quickTrade['settler'] === 'green') {
            $quickTrade['settler'] = 'yellow';
        }

        $robotTrade = [
            'evaluator' => $robotEvaluator['state'],
            'evaluator_detail' => $robotEvaluator,
            'market_feed' => $futureLiveFeed['state'],
            'market_feed_detail' => $futureLiveFeed,
        ];

        $futureTrade = [
            'live_feed' => $futureLiveFeed['state'],
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
            $query->where('exchange', 'binance');
        }
        if (Schema::hasColumn('market_feed_status', 'market_type')) {
            $query->where('market_type', 'futures');
        }

        $row = $query->orderByDesc('last_tick_at')->first();
        if (! $row) {
            return ['state' => 'red', 'reason' => 'no_futures_market_feed_tick'];
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
