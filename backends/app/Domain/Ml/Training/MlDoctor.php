<?php

namespace App\Domain\Ml\Training;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\MlCandleCollector;
use App\Domain\Ml\Shadow\MlHistoryWindow;
use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Models\MlCandle;
use App\Models\MlFeatureRow;
use App\Models\MlModel;
use App\Models\MlTrainingRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One pass over everything the ML shadow pipeline depends on, with the few
 * fixes that are safe to apply automatically:
 *
 *   fixed with --fix   TRAINING model rows whose trainer is gone, RUNNING runs
 *                      with a dead heartbeat (reaper), training locks held by
 *                      nothing.
 *   never fixed        killing a live trainer, restarting services, changing
 *                      .env or supervisor, rebuilding features or history. Those
 *                      are reported with the exact next step.
 *
 * Level order: OK < INFO < WARN < FAIL. FIXED counts as OK.
 */
final class MlDoctor
{
    public const OK = 'OK';

    public const INFO = 'INFO';

    public const FIXED = 'FIXED';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    /** A TRAINING model row younger than this is never called stale. */
    private const STALE_MODEL_MINUTES = 20;

    /** @var list<array{level: string, section: string, message: string}> */
    private array $findings = [];

    public function __construct(
        private readonly TrainingSystemStats $system,
        private readonly TrainingQueueStats $queue,
        private readonly TrainingPipelineHeartbeats $heartbeats,
        private readonly TrainingReaper $reaper,
        private readonly TrainingRunService $runs,
        private readonly string $procRoot = '/proc',
    ) {}

    /** @return list<array{level: string, section: string, message: string}> */
    public function run(bool $fix): array
    {
        $this->findings = [];
        $this->config();
        $processes = $this->processes();
        $this->registry($fix, $processes);
        $this->locks($fix, $processes);
        $this->queueHealth();
        $this->data();
        $this->host();
        $this->scheduler();

        return $this->findings;
    }

    private function add(string $level, string $section, string $message): void
    {
        $this->findings[] = ['level' => $level, 'section' => $section, 'message' => $message];
    }

    /** What the training will actually use, and settings that make queue runs fail. */
    private function config(): void
    {
        $s = 'Config';
        if (! (bool) config('ml.enabled', false)) {
            $this->add(self::FAIL, $s, 'ML shadow learning is disabled (AUTOFINX_ML_SHADOW_ENABLED). Nothing is collected or trained.');

            return;
        }
        $t = (array) config('ml.training', []);
        $timeout = (int) ($t['timeout_seconds'] ?? 1800);
        $retryAfter = (int) config('queue.connections.'.($t['connection'] ?? 'ml_training').'.retry_after', 0);
        $this->add(self::INFO, $s, sprintf('Training uses up to %s rows, window %s, recency half-life %s days, python timeout %ss, types %s.',
            number_format((int) ($t['max_training_rows'] ?? 0)),
            ((int) ($t['window_months'] ?? 0)) === 0 ? 'all history' : ((int) $t['window_months']).' months',
            (float) ($t['recency_half_life_days'] ?? 0) > 0 ? (string) $t['recency_half_life_days'] : 'off',
            $timeout, implode(',', (array) ($t['candidate_types'] ?? []))));
        if ($retryAfter > 0 && $retryAfter <= $timeout + 120) {
            $this->add(self::FAIL, $s, "queue retry_after ({$retryAfter}s) is not above the training timeout + 120s ({$timeout}s + 120s): a second worker would re-run a model that is still training. Raise AUTOFINX_ML_TRAINING_RETRY_AFTER or lower AUTOFINX_ML_TRAINING_TIMEOUT_SECONDS.");
        } else {
            $this->add(self::OK, $s, "queue retry_after {$retryAfter}s is above the job timeout.");
        }
        $fiveYearRows = (int) round(60 * 30.4375 * 24 * 12);
        if ((int) ($t['window_months'] ?? 0) === 0 && (int) ($t['max_training_rows'] ?? 0) > 0 && (int) $t['max_training_rows'] < $fiveYearRows) {
            $this->add(self::WARN, $s, "max_training_rows ({$t['max_training_rows']}) is below ~{$fiveYearRows} (5 years of 5m rows): only the newest rows are fitted.");
        }
    }

    /**
     * Trainer processes (php `ml:train` and the python trainers) from /proc.
     * Null when /proc is unavailable (not Linux): process-based fixes are skipped.
     *
     * @return list<array{pid: int, ppid: int, kind: string, cmd: string}>|null
     */
    private function processes(): ?array
    {
        $s = 'Processes';
        if (! is_dir($this->procRoot) || ! is_readable($this->procRoot.'/loadavg')) {
            $this->add(self::INFO, $s, 'Process table is not readable on this platform; process checks and process-based fixes are skipped.');

            return null;
        }
        $found = [];
        foreach (scandir($this->procRoot) ?: [] as $entry) {
            if (! ctype_digit($entry)) {
                continue;
            }
            $cmd = @file_get_contents($this->procRoot.'/'.$entry.'/cmdline');
            if ($cmd === false || $cmd === '') {
                continue;
            }
            $cmd = trim(str_replace("\0", ' ', $cmd));
            $kind = null;
            if (preg_match('/artisan\s+ml:train\s+[A-Z]/', $cmd) && ! str_contains($cmd, 'runuser')) {
                $kind = 'php';
            } elseif (preg_match('/train_(side_classifier|shadow_horizon)\.py/', $cmd)) {
                $kind = 'python';
            }
            if ($kind === null) {
                continue;
            }
            $stat = @file_get_contents($this->procRoot.'/'.$entry.'/stat');
            $ppid = $stat !== false && preg_match('/^\d+ \(.*\) \S (\d+)/s', $stat, $m) ? (int) $m[1] : 0;
            $found[] = ['pid' => (int) $entry, 'ppid' => $ppid, 'kind' => $kind, 'cmd' => mb_strimwidth($cmd, 0, 150, '...')];
        }

        $php = array_values(array_filter($found, static fn ($p) => $p['kind'] === 'php'));
        $python = array_values(array_filter($found, static fn ($p) => $p['kind'] === 'python'));
        if (count($php) > 1) {
            $this->add(self::WARN, $s, count($php).' ml:train processes are running at once (they fight for RAM and CPU): '.implode(' | ', array_map(static fn ($p) => "pid {$p['pid']} {$p['cmd']}", $php)).'. Stop the extra one (touch /root/ml-batch.stop for a batch, or the dashboard Stop).');
        } elseif (count($php) === 1) {
            $this->add(self::OK, $s, "One trainer is running: pid {$php[0]['pid']} {$php[0]['cmd']}");
        } else {
            $this->add(self::INFO, $s, 'No training process is running right now.');
        }
        $phpPids = array_column($php, 'pid');
        foreach ($python as $p) {
            if (! in_array($p['ppid'], $phpPids, true)) {
                $this->add(self::WARN, $s, "Orphan python trainer pid {$p['pid']} (its ml:train parent is gone, parent pid {$p['ppid']}): it still uses RAM and CPU. {$p['cmd']}. Stop it with: kill {$p['pid']}");
            }
        }

        return $found;
    }

    /** @param  list<array<string, mixed>>|null  $processes */
    private function registry(bool $fix, ?array $processes): void
    {
        $s = 'Registry';
        $stall = max(30, (int) config('ml.training.stall_seconds', 180));
        $cutoff = MlTrainingRun::utcNow(-$stall);
        $dead = MlTrainingRun::query()->where('status', MlTrainingRun::RUNNING)
            ->where(fn ($q) => $q->where('heartbeat_at', '<', $cutoff)->orWhereNull('heartbeat_at'))->count();
        if ($dead > 0) {
            if ($fix) {
                $result = $this->reaper->run();
                $this->add(self::FIXED, $s, "{$dead} RUNNING run(s) had no heartbeat for {$stall}s: reaper ended them as INTERRUPTED (".json_encode($result).').');
            } else {
                $this->add(self::WARN, $s, "{$dead} RUNNING run(s) have had no heartbeat for {$stall}s (dead). Run with --fix, or wait for ml:training:reap.");
            }
        } else {
            $this->add(self::OK, $s, 'No RUNNING run has a dead heartbeat.');
        }

        // Old-code (or killed) trainings leave a model row at TRAINING forever.
        $liveRunModelIds = MlTrainingRun::query()->where('status', MlTrainingRun::RUNNING)->whereNotNull('model_id')->pluck('model_id')->all();
        $stale = MlModel::query()->where('status', 'TRAINING')
            ->where('created_at', '<', now()->subMinutes(self::STALE_MODEL_MINUTES))
            ->when($liveRunModelIds !== [], fn ($q) => $q->whereNotIn('id', $liveRunModelIds))->get();
        if ($stale->isEmpty()) {
            $this->add(self::OK, $s, 'No stuck TRAINING model rows.');
        } elseif ($processes === null) {
            $this->add(self::WARN, $s, $stale->count().' model row(s) are TRAINING without a recorded run, and processes cannot be checked here.');
        } elseif (array_filter($processes, static fn ($p) => $p['kind'] === 'php') !== []) {
            $this->add(self::INFO, $s, $stale->count().' TRAINING model row(s) have no run record (started by older code), and a trainer is running, so they are left alone.');
        } elseif ($fix) {
            MlModel::query()->whereIn('id', $stale->pluck('id'))->update(['status' => 'FAILED', 'decision_reason' => 'interrupted: no live trainer (cleaned by ml:doctor)']);
            $this->add(self::FIXED, $s, 'Marked '.$stale->count().' TRAINING model row(s) FAILED: no trainer process exists for them ('.$stale->map(fn ($m) => "{$m->symbol} h{$m->horizon_minutes}")->implode(', ').').');
        } else {
            $this->add(self::WARN, $s, $stale->count().' TRAINING model row(s) have no live trainer ('.$stale->map(fn ($m) => "{$m->symbol} h{$m->horizon_minutes}")->implode(', ').'). The dashboard shows them as training. Run with --fix.');
        }

        $failed = MlTrainingRun::query()->whereIn('status', [MlTrainingRun::FAILED, MlTrainingRun::INTERRUPTED])
            ->where('finished_at', '>=', MlTrainingRun::utcNow(-86400))->orderByDesc('id')->limit(3)->get(['symbol', 'horizon_minutes', 'status', 'error_code', 'error_message']);
        foreach ($failed as $run) {
            $this->add(self::WARN, $s, "Recent {$run->status}: {$run->symbol} h{$run->horizon_minutes} ({$run->error_code}) ".mb_strimwidth((string) $run->error_message, 0, 140, '...'));
        }
    }

    /** A training lock held by nothing blocks every new run of that pair until its TTL. */
    private function locks(bool $fix, ?array $processes): void
    {
        $s = 'Locks';
        if ($processes === null) {
            return;
        }
        if (array_filter($processes, static fn ($p) => $p['kind'] === 'php') !== []
            || MlTrainingRun::query()->where('status', MlTrainingRun::RUNNING)->exists()) {
            $this->add(self::INFO, $s, 'A training is active, so no lock is touched.');

            return;
        }
        $held = [];
        foreach ((array) config('ml.symbols', []) as $symbol) {
            foreach (app(FeatureLabelService::class)->horizons() as $horizon) {
                $key = MlShadowTrainer::lockKey($symbol, (int) $horizon);
                try {
                    $probe = Cache::lock($key, 5);
                    if ($probe->get()) {
                        $probe->release();

                        continue;
                    }
                    $held[] = [$symbol, (int) $horizon, $key];
                } catch (Throwable) {
                    $this->add(self::WARN, $s, 'The cache store could not be queried for training locks.');

                    return;
                }
            }
        }
        if ($held === []) {
            $this->add(self::OK, $s, 'No orphaned training locks.');

            return;
        }
        $names = implode(', ', array_map(static fn ($h) => "{$h[0]} h{$h[1]}", $held));
        if (! $fix) {
            $this->add(self::WARN, $s, count($held)." training lock(s) held while nothing is training ({$names}); new runs would say already active. Run with --fix.");

            return;
        }
        foreach ($held as [$symbol, $horizon, $key]) {
            Cache::lock($key)->forceRelease();
            $this->runs->releaseUniqueLock($symbol, $horizon);
        }
        $this->add(self::FIXED, $s, 'Released '.count($held)." orphaned training lock(s): {$names}.");
    }

    private function queueHealth(): void
    {
        $s = 'Queue';
        $config = (array) config('queue.connections.'.$this->queue->connection(), []);
        $driver = (string) ($config['driver'] ?? 'unknown');
        $started = microtime(true);
        $summary = $this->queue->summary();
        $ms = (int) round((microtime(true) - $started) * 1000);
        if ($summary['pending'] === null) {
            $this->add(self::FAIL, $s, "The ml_training queue ({$driver}) cannot be read: workers cannot fetch jobs either, so runs started from the dashboard stay QUEUED. Check the database and the 'jobs' table.");

            return;
        }
        $this->add($ms > 1500 ? self::WARN : self::OK, $s, "{$driver} queue: {$summary['pending']} pending, ".($summary['reserved'] ?? 0)." reserved, {$summary['failed']} failed in 24h; the queue query took {$ms} ms".($ms > 1500 ? ' (the database is slow: the worker poll is likely hitting lock waits)' : '').'.');

        $oldest = null;
        try {
            if ($driver === 'database') {
                $oldest = DB::connection($config['connection'] ?? null)->table((string) ($config['table'] ?? 'jobs'))
                    ->where('queue', $this->queue->queueName())->whereNull('reserved_at')->min('available_at');
            }
        } catch (Throwable) {
        }
        if ($oldest !== null && now()->getTimestamp() - (int) $oldest > 600) {
            $this->add(self::WARN, $s, 'The oldest pending job has waited '.intdiv(now()->getTimestamp() - (int) $oldest, 60).' min: the ml_training worker is not picking jobs up. Check: supervisorctl status autofinx-ml-training, and the worker errors in storage/logs/laravel.log.');
        }
        $queuedRuns = MlTrainingRun::query()->where('status', MlTrainingRun::QUEUED)->where('queued_at', '<', MlTrainingRun::utcNow(-900))->count();
        if ($queuedRuns > 0 && ($summary['pending'] ?? 0) === 0 && ($summary['reserved'] ?? 0) === 0) {
            $this->add(self::WARN, $s, "{$queuedRuns} run(s) are QUEUED for over 15 min but the queue is empty: their jobs were lost. Retry them from the dashboard.");
        }
        foreach ($this->queue->failedRecent(3) as $failure) {
            $this->add(self::INFO, $s, 'Failed job: '.mb_strimwidth(json_encode($failure) ?: '', 0, 200, '...'));
        }
    }

    /** Candles, features and history per coin, using only indexed MAX/MIN lookups. */
    private function data(): void
    {
        $s = 'Data';
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $intervalMs = MlCandleCollector::intervalMs($timeframe);
        $nowMs = now('UTC')->getTimestampMs();
        foreach ((array) config('ml.symbols', []) as $symbol) {
            $latest = MlCandle::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->max('open_time_ms');
            if ($latest === null) {
                $this->add(self::FAIL, $s, "{$symbol}: no {$timeframe} candles at all. Run ml:candles:backfill.");

                continue;
            }
            $ageSeconds = max(0, intdiv($nowMs - ((int) $latest + $intervalMs), 1000));
            $state = TrainingOverviewService::candleState($ageSeconds, intdiv($intervalMs, 1000));
            $note = "{$symbol}: newest {$timeframe} candle closed {$ageSeconds}s ago ({$state})";
            $this->add($state === 'live' ? self::OK : ($state === 'lagging' ? self::WARN : self::FAIL), $s, $note.($state === 'live' ? '.' : '. Check ml:candles:sync and the scheduler.'));

            $earliest = MlCandle::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->min('open_time_ms');
            $wanted = MlHistoryWindow::startMs($symbol);
            if ($earliest !== null && (int) $earliest > $wanted + 3 * 86_400_000) {
                $missingDays = intdiv((int) $earliest - $wanted, 86_400_000);
                $this->add(self::WARN, $s, "{$symbol}: history starts {$missingDays} days later than the {$this->months($symbol)}-month target. Run: artisan ml:candles:backfill --symbol={$symbol} --no-build, then ml:features:build --symbol={$symbol} --full.");
            }

            $feature = MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->max('anchor_time_ms');
            if ($feature === null) {
                $this->add(self::FAIL, $s, "{$symbol}: no feature rows. Run: artisan ml:features:build --symbol={$symbol} --full.");
            } elseif ((int) $latest - (int) $feature > 12 * $intervalMs) {
                $this->add(self::WARN, $s, "{$symbol}: features are ".intdiv((int) $latest - (int) $feature, $intervalMs)." candles behind the newest candle. Run: artisan ml:features:build --symbol={$symbol}.");
            }
        }
    }

    private function months(string $symbol): int
    {
        return MlHistoryWindow::months($symbol);
    }

    private function host(): void
    {
        $s = 'Host';
        $host = $this->system->host();
        $health = $this->system->health($host);
        if ($host['load'] !== null) {
            $this->add(self::INFO, $s, sprintf('load %s %s %s on %d cores; memory available %s MB; swap used %s%%; disk free %s GB.',
                $host['load']['m1'], $host['load']['m5'], $host['load']['m15'], (int) $host['cores'],
                $host['memory']['available_mb'] ?? '?', $host['swap']['used_pct'] ?? '?', $host['disk']['free_gb'] ?? '?'));
        }
        $level = ['ok' => self::OK, 'warning' => self::WARN, 'critical' => self::FAIL][$health['level']] ?? self::INFO;
        foreach ($health['reasons'] ?: ['Host resources are within limits.'] as $reason) {
            $this->add($level, $s, $reason);
        }
        if ($level !== self::OK) {
            $this->add(self::INFO, $s, 'A model that takes ~3 minutes on a healthy host takes hours when RAM and swap are exhausted. Free memory (stop what you do not need, or add RAM) before expecting training to speed up.');
        }
    }

    private function scheduler(): void
    {
        $s = 'Scheduler';
        $late = array_filter($this->heartbeats->all(), static fn ($row) => $row['state'] === 'late');
        foreach ($late as $row) {
            $this->add(self::WARN, $s, "{$row['command']} last ran {$row['age_seconds']}s ago: the scheduler or its overlap lock is stuck (artisan schedule:clear-cache, then restart autofinx-scheduler).");
        }
        if ($late === []) {
            $this->add(self::OK, $s, 'Every scheduled ml:* command ran on time.');
        }
    }
}
