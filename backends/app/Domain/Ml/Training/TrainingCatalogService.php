<?php

namespace App\Domain\Ml\Training;

use App\Domain\Ml\Shadow\MlCandleCollector;
use App\Models\MlCandle;
use App\Models\MlModel;

/** Read-only candle chart data and the model registry list for the dashboard. */
class TrainingCatalogService
{
    /** @return array<string, mixed> */
    public function candles(string $symbol, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $intervalMs = MlCandleCollector::intervalMs($timeframe);
        $rows = MlCandle::query()->where('provider', (string) config('ml.provider', 'binance_futures'))->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->orderByDesc('open_time_ms')->limit($limit)->get(['open_time_ms', 'open', 'high', 'low', 'close', 'volume'])->reverse()->values();

        $latestCloseMs = $rows->isNotEmpty() ? (int) $rows->last()->open_time_ms + $intervalMs : null;
        $age = $latestCloseMs !== null ? max(0, intdiv(now('UTC')->getTimestampMs() - $latestCloseMs, 1000)) : null;

        return [
            'symbol' => $symbol, 'timeframe' => $timeframe, 'generated_at' => now('UTC')->toIso8601String(),
            'latest_close' => $latestCloseMs !== null ? gmdate('c', intdiv($latestCloseMs, 1000)) : null,
            'age_seconds' => $age, 'state' => TrainingOverviewService::candleState($age, intdiv($intervalMs, 1000)),
            'candles' => $rows->map(fn (MlCandle $c): array => [
                't' => (int) $c->open_time_ms, 'o' => (float) $c->open, 'h' => (float) $c->high, 'l' => (float) $c->low, 'c' => (float) $c->close, 'v' => (float) $c->volume,
            ])->all(),
        ];
    }

    /**
     * @param  array{symbol?:?string, horizon_minutes?:?int}  $filters
     * @return array{data: list<array<string, mixed>>}
     */
    public function models(array $filters, int $limit): array
    {
        $query = MlModel::query()->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($filters['symbol'])) {
            $query->where('symbol', strtoupper((string) $filters['symbol']));
        }
        if (! empty($filters['horizon_minutes'])) {
            $query->where('horizon_minutes', (int) $filters['horizon_minutes']);
        }

        return ['data' => $query->limit(max(1, min(200, $limit)))->get()->map(fn (MlModel $m): array => [
            'model_version' => $m->model_version, 'symbol' => $m->symbol, 'horizon_minutes' => (int) $m->horizon_minutes,
            'model_type' => $m->model_type, 'status' => $m->status, 'decision_reason' => $m->decision_reason,
            'trained_at' => $m->trained_at?->toIso8601String(), 'promoted_at' => $m->promoted_at?->toIso8601String(),
            'samples' => ['total' => (int) $m->total_samples, 'train' => (int) $m->train_samples, 'holdout' => (int) $m->holdout_samples],
            'holdout_summary' => $this->holdoutSummary($m), 'comparison' => $m->comparison,
        ])->all()];
    }

    /** @return object the headline numbers of the holdout, whichever model type produced them */
    private function holdoutSummary(MlModel $model): object
    {
        $holdout = (array) data_get($model->metrics, 'holdout', []);
        $net = (array) ($holdout['non_overlapping'] ?? []);

        return (object) array_filter([
            'trades' => $net['trades'] ?? null, 'total_net_pct' => $net['total_net_pct'] ?? null, 'average_net_pct' => $net['average_net_pct'] ?? null,
            'net_win_rate_pct' => $net['net_win_rate_pct'] ?? null, 't_stat' => $net['t_stat'] ?? null,
            'rows' => $holdout['rows'] ?? null, 'mae_pct' => $holdout['mae_pct'] ?? null, 'baseline_zero_mae_pct' => $holdout['baseline_zero_mae_pct'] ?? null,
            'direction_hit_pct' => $holdout['direction_hit_pct'] ?? null,
        ], static fn ($v) => $v !== null);
    }
}
