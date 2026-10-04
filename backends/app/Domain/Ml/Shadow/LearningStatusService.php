<?php

namespace App\Domain\Ml\Shadow;

use App\Models\MlBackfillCheckpoint;
use App\Models\MlCandle;
use App\Models\MlCandleRejection;
use App\Models\MlLabel;
use App\Models\MlModel;

/** Read-only snapshot of the learning loop for the Quick Trade panel and `ml:status`. */
class LearningStatusService
{
    public function __construct(
        private readonly MlModelRegistry $registry,
        private readonly FeatureLabelService $labels,
        private readonly ShadowEvaluationService $evaluation,
        private readonly ShadowPredictionService $predictions,
    ) {}

    /** @return array<string, mixed> */
    public function status(string $symbol): array
    {
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $candles = MlCandle::query()->where('provider', (string) config('ml.provider', 'binance_futures'))
            ->where('symbol', $symbol)->where('timeframe', $timeframe);
        $latestOpen = (clone $candles)->max('open_time_ms');
        $earliestOpen = (clone $candles)->min('open_time_ms');
        $interval = MlCandleCollector::intervalMs($timeframe);

        $rejections = MlCandleRejection::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->selectRaw('reason, resolved, count(*) as total')->groupBy('reason', 'resolved')->get()
            ->groupBy('reason')->map(fn ($rows) => [
                'total' => (int) $rows->sum('total'),
                'unresolved' => (int) $rows->where('resolved', false)->sum('total'),
            ])->all();

        $horizons = [];
        foreach ($this->labels->horizons() as $horizon) {
            $counts = MlLabel::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->where('horizon_minutes', $horizon)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $champion = $this->registry->champion($symbol, $horizon);
            $lastAttempt = MlModel::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)
                ->orderByDesc('created_at')->first();
            $lastCandidate = MlModel::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)
                ->whereIn('status', ['CANDIDATE', 'CHAMPION', 'REJECTED', 'RETIRED'])
                ->when($champion, fn ($q) => $q->whereKeyNot($champion->id))
                ->orderByDesc('trained_at')->first();
            $running = MlModel::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)->where('status', 'TRAINING')
                ->where('created_at', '>=', now()->subSeconds((int) config('ml.training.timeout_seconds', 1800) + 300))
                ->orderByDesc('created_at')->first();
            $horizons[] = [
                'horizon_minutes' => $horizon,
                'tradable' => in_array($horizon, MlPaperAutopilotService::tradeHorizons(), true),
                // A training attempt that is running right now (started, not finished).
                'training_now' => $running ? ['model_type' => $running->model_type, 'started_at' => $running->created_at?->toIso8601String()] : null,
                'labels' => ['pending' => (int) ($counts['PENDING'] ?? 0), 'mature' => (int) ($counts['MATURE'] ?? 0)],
                'retrain' => app(RetrainEligibility::class)->check($symbol, $horizon),
                'last_training' => $lastAttempt ? [
                    'status' => $lastAttempt->status, 'model_version' => $lastAttempt->model_version,
                    'at' => ($lastAttempt->trained_at ?? $lastAttempt->created_at)?->toIso8601String(),
                    'reason' => $lastAttempt->decision_reason,
                ] : null,
                'champion' => $champion ? $this->predictions->modelSummary($champion) : null,
                'last_candidate' => $lastCandidate ? $this->predictions->modelSummary($lastCandidate) + [
                    'comparison' => $lastCandidate->comparison,
                ] : null,
                'live_evaluation' => $this->evaluation->metrics($symbol, $horizon),
            ];
        }

        // History target for this symbol and how much of it is collected.
        $months = MlHistoryWindow::months($symbol);
        $targetCandles = (int) round($months * 30.4375 * 86_400_000 / $interval);
        $collected = (clone $candles)->count();
        $checkpoint = MlBackfillCheckpoint::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->orderByDesc('updated_at')->first();
        $schedule = (string) config('ml.training.schedule_utc', '03:05');
        $nextRetrain = now('UTC')->setTimeFromTimeString($schedule);
        if ($nextRetrain->isPast()) {
            $nextRetrain->addDay();
        }

        return [
            'enabled' => (bool) config('ml.enabled', false),
            'history' => [
                'target_months' => $months,
                'target_candles' => $targetCandles,
                'coverage_pct' => $targetCandles > 0 ? round(min(100, $collected / $targetCandles * 100), 1) : null,
                'backfill' => $checkpoint ? [
                    'status' => $checkpoint->status, 'pages' => (int) $checkpoint->pages, 'rows_written' => (int) $checkpoint->rows_written,
                    'cursor' => gmdate('c', intdiv((int) $checkpoint->cursor_ms, 1000)), 'updated_at' => $checkpoint->updated_at?->toIso8601String(),
                    'range_from' => gmdate('c', intdiv((int) $checkpoint->range_from_ms, 1000)),
                    'last_error' => $checkpoint->last_error ? mb_substr((string) $checkpoint->last_error, 0, 300) : null,
                ] : null,
            ],
            'next_retrain_at' => $nextRetrain->toIso8601String(),
            'mode' => 'shadow_only', 'execution' => false, 'influence' => false,
            'symbol' => $symbol, 'base_timeframe' => $timeframe,
            'candles' => [
                'collected' => $collected,
                'earliest_open' => $earliestOpen !== null ? gmdate('c', intdiv((int) $earliestOpen, 1000)) : null,
                'latest_close' => $latestOpen !== null ? gmdate('c', intdiv((int) $latestOpen + $interval, 1000)) : null,
                'latest_close_age_seconds' => $latestOpen !== null ? max(0, intdiv(now('UTC')->getTimestampMs() - ((int) $latestOpen + $interval), 1000)) : null,
                'rejected' => array_sum(array_column($rejections, 'total')),
                'rejections_by_reason' => $rejections,
            ],
            'horizons' => $horizons,
        ];
    }
}
