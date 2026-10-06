<?php

namespace App\Domain\Ml\Training;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\MlCandleCollector;
use App\Domain\Ml\Shadow\MlPaperAutopilotService;
use App\Domain\Ml\Shadow\ShadowFeatureBuilder;
use App\Models\MlBackfillCheckpoint;
use App\Models\MlCandle;
use App\Models\MlChampion;
use App\Models\MlFeatureRow;
use App\Models\MlFundingRate;
use App\Models\MlModel;
use App\Models\MlTrainingRun;
use Illuminate\Support\Facades\DB;

/**
 * GET /overview. Reads only cheap things per request: the indexed latest
 * candle / feature / funding row, the (small) runs table, the jobs table by
 * queue name. Anything that needs a COUNT over a big ml table comes from the
 * 5 minute TrainingCounts cache and is labelled with counts_as_of.
 */
class TrainingOverviewService
{
    private const BACKFILL_STALLED_MINUTES = 10;

    private const QUEUE_BACKLOG_WARN = 10;

    private const RECENT_RUN_ALERT_HOURS = 24;

    public function __construct(
        private readonly TrainingCounts $counts,
        private readonly TrainingRunPresenter $presenter,
        private readonly TrainingQueueStats $queue,
        private readonly TrainingPipelineHeartbeats $pipeline,
        private readonly TrainingSystemStats $system,
        private readonly FeatureLabelService $features,
    ) {}

    /** Newest closed candle within 2 intervals = live, within 10 = lagging, else stale. */
    public static function candleState(?int $ageSeconds, int $intervalSeconds): string
    {
        return match (true) {
            $ageSeconds === null => 'missing',
            $ageSeconds <= 2 * $intervalSeconds => 'live',
            $ageSeconds <= 10 * $intervalSeconds => 'lagging',
            default => 'stale',
        };
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $enabled = (bool) config('ml.enabled', false);
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $provider = (string) config('ml.provider', 'binance_futures');
        $intervalMs = MlCandleCollector::intervalMs($timeframe);
        $nowMs = now('UTC')->getTimestampMs();
        $horizons = $this->features->horizons();
        $counts = $this->counts->get();

        $champions = $this->champions();
        $activeRuns = MlTrainingRun::query()->whereIn('status', MlTrainingRun::ACTIVE)->orderBy('id')->get();
        $activeSummaries = collect($this->presenter->summaries($activeRuns))->keyBy('id');
        $activeByPair = $activeRuns->groupBy(fn (MlTrainingRun $r) => $r->symbol.':'.$r->horizon_minutes)->map(fn ($g) => $g->last());
        $lastByPair = $this->lastTerminalRuns();
        $lastSummaries = collect($this->presenter->summaries($lastByPair->values()))->keyBy('id');

        $symbols = [];
        $alerts = [];
        $stallSeconds = (int) config('ml.training.stall_seconds', 180);
        foreach ((array) config('ml.symbols', []) as $symbol) {
            $c = $counts['symbols'][$symbol] ?? ['collected' => 0, 'target' => 0, 'rejected' => 0, 'feature_rows' => 0, 'pending' => 0, 'mature' => 0, 'retrain' => []];
            $latestOpen = MlCandle::query()->where('provider', $provider)->where('symbol', $symbol)->where('timeframe', $timeframe)->max('open_time_ms');
            $latestCloseMs = $latestOpen !== null ? (int) $latestOpen + $intervalMs : null;
            $age = $latestCloseMs !== null ? max(0, intdiv($nowMs - $latestCloseMs, 1000)) : null;
            $candleState = self::candleState($age, intdiv($intervalMs, 1000));

            $checkpoint = MlBackfillCheckpoint::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->orderByDesc('updated_at')->first();
            $backfill = $this->backfill($checkpoint);

            $symbols[] = [
                'symbol' => $symbol,
                'candles' => [
                    'state' => $candleState,
                    'latest_close' => $latestCloseMs !== null ? gmdate('c', intdiv($latestCloseMs, 1000)) : null,
                    'age_seconds' => $age, 'collected' => $c['collected'], 'target' => $c['target'],
                    'coverage_pct' => $c['target'] > 0 ? round(min(100, $c['collected'] / $c['target'] * 100), 1) : null,
                    'rejected' => $c['rejected'],
                ],
                'backfill' => $backfill,
                'features' => ['rows' => $c['feature_rows'], 'state' => $this->featureState($symbol, $timeframe, $c['feature_rows'], $latestCloseMs, $intervalMs, $backfill['status'] ?? null)],
                'labels' => ['pending' => $c['pending'], 'mature' => $c['mature']],
                'funding' => $this->funding($symbol, $nowMs),
                'horizons' => array_map(function (int $horizon) use ($symbol, $c, $champions, $activeByPair, $activeSummaries, $lastByPair, $lastSummaries): array {
                    $key = $symbol.':'.$horizon;
                    $champion = $champions[$key] ?? null;
                    $last = $lastByPair->get($key);
                    $active = $activeByPair->get($key);
                    $activeRow = $active ? $activeSummaries->get($active->id) : null;
                    $lastRow = $last ? $lastSummaries->get($last->id) : null;

                    return [
                        'horizon_minutes' => $horizon,
                        'tradable' => in_array($horizon, MlPaperAutopilotService::tradeHorizons(), true),
                        'champion' => $champion,
                        'last_run' => $lastRow ? array_intersect_key($lastRow, array_flip(['id', 'status', 'outcome', 'outcome_reason', 'finished_at'])) : null,
                        'active_run' => $activeRow ? array_intersect_key($activeRow, array_flip(['id', 'status', 'phase', 'progress_pct', 'started_at', 'heartbeat_age_seconds'])) : null,
                        'retrain' => $c['retrain'][$horizon] ?? ['eligible' => false, 'reason' => 'unknown', 'new_mature' => 0, 'required' => 0],
                    ];
                }, $horizons),
            ];

            if ($enabled && $candleState === 'stale') {
                $alerts[] = $this->alert('candles_stale', 'warning', "{$symbol} candles are stale", 'The newest closed candle is '.$this->human((int) $age).' old; shadow predictions for this coin are skipped.', gmdate('c', intdiv((int) $latestCloseMs, 1000)), $symbol);
            }
            if ($backfill && in_array($backfill['status'], ['FAILED', 'STALLED'], true)) {
                $failed = $backfill['status'] === 'FAILED';
                $alerts[] = $this->alert($failed ? 'backfill_failed' : 'backfill_stalled', 'warning', $symbol.($failed ? ' backfill failed' : ' backfill has stalled'),
                    $failed ? (string) ($backfill['last_error'] ?? 'The backfill stopped with an error; the checkpoint is kept.') : 'The backfill has not progressed for over '.self::BACKFILL_STALLED_MINUTES.' minutes.',
                    $backfill['updated_at'] ?? now('UTC')->toIso8601String(), $symbol, null, ['type' => 'resume_backfill', 'symbol' => $symbol]);
            }
        }

        foreach ($activeRuns as $run) {
            $row = $activeSummaries->get($run->id);
            if ($run->status === MlTrainingRun::RUNNING && ($row['heartbeat_age_seconds'] ?? 0) > $stallSeconds) {
                $alerts[] = $this->alert('run_stalled', 'critical', "{$run->symbol} h{$run->horizon_minutes} training has stalled",
                    "No heartbeat for {$row['heartbeat_age_seconds']} s (limit {$stallSeconds} s). The reaper marks it interrupted and re-queues it shortly.",
                    (string) ($row['heartbeat_at'] ?? $row['started_at']), $run->symbol, (int) $run->horizon_minutes, ['type' => 'open_run', 'run_id' => $run->id], $run->id);
            }
        }
        $recentSince = now()->subHours(self::RECENT_RUN_ALERT_HOURS);
        foreach ($lastByPair as $key => $run) {
            $row = $lastSummaries->get($run->id);
            if ($activeByPair->has($key) || ! in_array($run->status, [MlTrainingRun::FAILED, MlTrainingRun::INTERRUPTED], true) || $run->finished_at === null || $run->finished_at->lt($recentSince)) {
                continue;
            }
            $interrupted = $run->status === MlTrainingRun::INTERRUPTED;
            $alerts[] = $this->alert($interrupted ? 'run_interrupted' : 'run_failed', 'warning', "{$run->symbol} h{$run->horizon_minutes} training ".($interrupted ? 'was interrupted' : 'failed'),
                (string) ($run->error_message ?: 'See the run for details.'), $run->finished_at->toIso8601String(), $run->symbol, (int) $run->horizon_minutes,
                ($row['can_retry'] ?? false) ? ['type' => 'retry_run', 'run_id' => $run->id] : ['type' => 'open_run', 'run_id' => $run->id], $run->id);
        }

        $queue = $this->queue->summary();
        if (($queue['pending'] ?? 0) >= self::QUEUE_BACKLOG_WARN) {
            $alerts[] = $this->alert('queue_backlog', 'warning', 'Training queue backlog', "{$queue['pending']} jobs are waiting on the ml_training queue; one worker trains one model at a time.", now('UTC')->toIso8601String());
        }

        $host = $this->system->host();
        $t = $this->system->thresholds();
        if (($host['memory']['used_pct'] ?? 0) >= $t['memory_used_pct_critical']) {
            $alerts[] = $this->alert('host_memory_critical', 'critical', 'Host memory is critical', "Memory is {$host['memory']['used_pct']}% used ({$host['memory']['available_mb']} MB available).", now('UTC')->toIso8601String());
        }
        if (($host['swap']['total_mb'] ?? 0) > 0 && ($host['swap']['used_pct'] ?? 0) >= $t['swap_used_pct_critical']) {
            $alerts[] = $this->alert('host_swap_critical', 'critical', 'Host swap is critical', "Swap is {$host['swap']['used_pct']}% used; training will be very slow.", now('UTC')->toIso8601String());
        }

        $pipeline = $this->pipeline->all();
        if ($enabled) {
            foreach ($pipeline as $job) {
                if ($job['state'] === 'late') {
                    $alerts[] = $this->alert('scheduler_late', 'warning', "{$job['command']} is late", 'Last started '.$this->human((int) $job['age_seconds']).' ago.', (string) $job['last_started_at'], null, null, null, $job['command']);
                }
            }
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn (array $a, array $b): int => [$rank[$a['severity']], $a['since']] <=> [$rank[$b['severity']], $b['since']]);

        return [
            'generated_at' => now('UTC')->toIso8601String(),
            'enabled' => $enabled,
            'mode' => 'shadow_only',
            'counts_as_of' => $counts['as_of'],
            'alerts' => $alerts,
            'symbols' => $symbols,
            'active_runs' => array_values($activeSummaries->all()),
            'queue' => ['pending' => $queue['pending'], 'reserved' => $queue['reserved'], 'failed' => $queue['failed']],
            'pipeline_jobs' => $pipeline,
        ];
    }

    /** @return array<string, mixed>|null */
    private function backfill(?MlBackfillCheckpoint $cp): ?array
    {
        if ($cp === null) {
            return null;
        }
        $status = (string) $cp->status;
        if ($status === 'RUNNING' && $cp->updated_at !== null && $cp->updated_at->lt(now()->subMinutes(self::BACKFILL_STALLED_MINUTES))) {
            $status = 'STALLED';
        }

        return [
            'status' => $status, 'pages' => (int) $cp->pages, 'rows_written' => (int) $cp->rows_written,
            'cursor' => gmdate('c', intdiv((int) $cp->cursor_ms, 1000)), 'updated_at' => $cp->updated_at?->toIso8601String(),
            'last_error' => $cp->last_error ? mb_substr(app(TrainingRunRecorder::class)->redact((string) $cp->last_error), 0, 300) : null,
        ];
    }

    private function featureState(string $symbol, string $timeframe, int $rows, ?int $latestCloseMs, int $intervalMs, ?string $backfillStatus): string
    {
        if ($rows <= 0) {
            return 'missing';
        }
        if ($backfillStatus === 'RUNNING') {
            return 'building';
        }
        $anchor = MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->where('feature_schema_version', ShadowFeatureBuilder::SCHEMA_VERSION)->max('anchor_time_ms');

        return $anchor !== null && $latestCloseMs !== null && $latestCloseMs - (int) $anchor > 3 * $intervalMs ? 'building' : 'ok';
    }

    /** @return array{latest: string|null, state: string} */
    private function funding(string $symbol, int $nowMs): array
    {
        $latest = MlFundingRate::query()->where('symbol', $symbol)->max('funding_time_ms');
        if ($latest === null) {
            return ['latest' => null, 'state' => 'missing'];
        }

        // Funding settles every 8 h and is synced hourly: older than ~10 h means sync stopped.
        return ['latest' => gmdate('c', intdiv((int) $latest, 1000)), 'state' => $nowMs - (int) $latest <= 10 * 3_600_000 ? 'ok' : 'stale'];
    }

    /** @return array<string, array{model_version: string|null, trained_at: string|null}> keyed SYMBOL:horizon */
    private function champions(): array
    {
        $pointers = MlChampion::query()->get(['symbol', 'horizon_minutes', 'model_id']);
        $models = MlModel::query()->whereIn('id', $pointers->pluck('model_id')->all())->get(['id', 'model_version', 'trained_at'])->keyBy('id');
        $out = [];
        foreach ($pointers as $pointer) {
            $model = $models->get($pointer->model_id);
            if ($model) {
                $out[$pointer->symbol.':'.$pointer->horizon_minutes] = ['model_version' => $model->model_version, 'trained_at' => $model->trained_at?->toIso8601String()];
            }
        }

        return $out;
    }

    /** Latest terminal run per symbol x horizon (UUIDv7: max(id) is the newest). */
    private function lastTerminalRuns(): \Illuminate\Support\Collection
    {
        $ids = DB::table('ml_training_runs')->whereIn('status', MlTrainingRun::TERMINAL)->groupBy('symbol', 'horizon_minutes')->selectRaw('max(id) as id')->pluck('id');

        return MlTrainingRun::query()->whereIn('id', $ids->all())->get()->keyBy(fn (MlTrainingRun $r) => $r->symbol.':'.$r->horizon_minutes);
    }

    /** @return array<string, mixed> */
    private function alert(string $code, string $severity, string $title, string $detail, string $since, ?string $symbol = null, ?int $horizon = null, ?array $action = null, ?string $key = null): array
    {
        $alert = ['id' => implode(':', array_filter([$code, $symbol, $horizon, $key], fn ($v) => $v !== null && $v !== '')), 'severity' => $severity, 'code' => $code, 'title' => $title, 'detail' => $detail];
        if ($symbol !== null) {
            $alert['symbol'] = $symbol;
        }
        if ($horizon !== null) {
            $alert['horizon_minutes'] = $horizon;
        }
        $alert['since'] = $since;
        if ($action !== null) {
            $alert['action'] = $action;
        }

        return $alert;
    }

    private function human(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => "{$seconds} s",
            $seconds < 7200 => intdiv($seconds, 60).' min',
            default => round($seconds / 3600, 1).' h',
        };
    }
}
