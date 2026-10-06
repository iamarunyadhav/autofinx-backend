<?php

namespace App\Domain\Ml\Training;

use App\Models\MlModel;
use App\Models\MlTrainingRun;

/** Read side of the runs endpoints: filtered list (UUIDv7 cursor) and one run's detail. */
class TrainingRunQuery
{
    public function __construct(private readonly TrainingRunPresenter $presenter) {}

    /**
     * @param  array{status?:?string, symbol?:?string, horizon_minutes?:?int, batch_id?:?string}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(array $filters, int $limit, ?string $cursor): array
    {
        $limit = max(1, min(100, $limit));
        $query = MlTrainingRun::query()->orderByDesc('id');
        if (! empty($filters['status'])) {
            $query->whereIn('status', array_map('strtoupper', array_filter(array_map('trim', explode(',', (string) $filters['status'])))));
        }
        if (! empty($filters['symbol'])) {
            $query->where('symbol', strtoupper((string) $filters['symbol']));
        }
        if (! empty($filters['horizon_minutes'])) {
            $query->where('horizon_minutes', (int) $filters['horizon_minutes']);
        }
        if (! empty($filters['batch_id'])) {
            $query->where('batch_id', (string) $filters['batch_id']);
        }
        if ($cursor !== null && $cursor !== '') {
            $query->where('id', '<', $cursor);
        }
        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return ['data' => $this->presenter->summaries($rows), 'next_cursor' => $more ? (string) $rows->last()->id : null];
    }

    /** @return array<string, mixed> */
    public function detail(MlTrainingRun $run): array
    {
        $model = $run->model_id ? MlModel::query()->find($run->model_id) : null;

        return [
            'run' => $this->presenter->summary($run),
            'log_tail' => $run->log_tail === null || $run->log_tail === '' ? [] : explode("\n", $run->log_tail),
            'model' => $model ? $this->modelDetail($model) : null,
            'attempts' => $this->presenter->summaries($this->chain($run)),
        ];
    }

    /** @return array<string, mixed> */
    private function modelDetail(MlModel $model): array
    {
        $metrics = (array) $model->metrics;
        $folds = data_get($metrics, 'walk_forward.folds');
        unset($metrics['walk_forward']);

        return [
            'model_version' => $model->model_version, 'status' => $model->status, 'decision_reason' => $model->decision_reason,
            'metrics' => $metrics, 'holdout' => data_get($model->metrics, 'holdout'), 'folds' => is_array($folds) ? $folds : [],
            'comparison' => $model->comparison,
        ];
    }

    /**
     * The retry chain the run belongs to, oldest attempt first (bounded).
     *
     * @return list<MlTrainingRun>
     */
    private function chain(MlTrainingRun $run): array
    {
        $chain = [$run->id => $run];
        $cursor = $run;
        for ($i = 0; $i < 20 && $cursor->retry_of; $i++) {
            $parent = MlTrainingRun::query()->find($cursor->retry_of);
            if ($parent === null) {
                break;
            }
            $chain[$parent->id] = $parent;
            $cursor = $parent;
        }
        $cursor = $run;
        for ($i = 0; $i < 20; $i++) {
            $child = MlTrainingRun::query()->where('retry_of', $cursor->id)->orderBy('id')->first();
            if ($child === null) {
                break;
            }
            $chain[$child->id] = $child;
            $cursor = $child;
        }
        $runs = array_values($chain);
        usort($runs, fn (MlTrainingRun $a, MlTrainingRun $b): int => [$a->attempt, $a->id] <=> [$b->attempt, $b->id]);

        return $runs;
    }
}
