<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Shadow\ShadowFeatureBuilder;
use App\Models\MlModel;
use Symfony\Component\Process\Process;

/**
 * Findings-only: how many DISTINCT non-overlapping episodes a regression
 * model's holdout signals really were, how they did after fees + funding, and
 * which features looked unusual at entry. Never builds an inverted strategy.
 */
final class MlAnalyzeSignalsCommand extends MlShadowCommand
{
    protected $signature = 'ml:analyze:signals {symbol} {horizon : Horizon in minutes} {--model= : model_version (default: every rejected regression model for this symbol x horizon that took trades)} {--json}';

    protected $description = 'Findings only: non-overlapping episodes and entry features of a regression model\'s holdout signals (e.g. the anti-predictive 240m models).';

    public function handle(MlShadowTrainer $trainer): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $horizon = (int) $this->argument('horizon');
        $models = MlModel::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)
            ->where(fn ($q) => $q->where('model_type', MlShadowTrainer::TYPE_REGRESSION)->orWhereNull('model_type'))
            ->when($this->option('model'), fn ($q) => $q->where('model_version', $this->option('model')))
            ->whereIn('status', ['REJECTED', 'CHAMPION', 'RETIRED', 'CANDIDATE'])
            ->orderBy('trained_at')->get()
            ->filter(fn (MlModel $m) => $this->option('model') || (int) data_get($m->metrics, 'holdout.fee_aware.trade_count', 0) > 0);
        if ($models->isEmpty()) {
            $this->warn('No matching regression model with holdout trades.');

            return self::FAILURE;
        }

        $python = (string) config('ml.training.python_bin');
        $script = (string) config('ml.training.analysis_script');
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $reports = [];
        foreach ($models as $model) {
            $schema = (string) $model->feature_schema_version;
            if (! array_key_exists($schema, ShadowFeatureBuilder::SCHEMAS) || ! is_file($model->artifact_dir.'/model.txt')) {
                $this->warn("{$model->model_version}: artifacts or schema unavailable; skipped.");

                continue;
            }
            $export = $trainer->export($symbol, $horizon, $timeframe, 'analysis-'.$model->id, $schema);
            $process = new Process([$python, $script, '--input', $export['data'], '--manifest', $export['manifest'], '--model-dir', $model->artifact_dir], base_path(), null, null, 900);
            $process->run();
            @unlink($export['data']);
            if (! $process->isSuccessful()) {
                $this->error("{$model->model_version}: analysis failed - ".mb_substr(trim($process->getErrorOutput()), -800));

                continue;
            }
            $report = json_decode($process->getOutput(), true) ?: [];
            $reports[] = ['model_version' => $model->model_version, 'schema' => $schema, 'status' => $model->status] + $report;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($reports, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        foreach ($reports as $r) {
            $sim = $r['non_overlapping'] ?? [];
            $this->info(sprintf('%s (%s, %s): raw signal rows %d -> distinct episodes %d (buy %d / sell %d), net win %s%%, total net %s%%',
                $r['model_version'], $r['schema'], $r['status'], $r['raw_signal_rows'] ?? 0, $r['distinct_episodes'] ?? 0,
                $r['episodes_by_side']['buy'] ?? 0, $r['episodes_by_side']['sell'] ?? 0,
                isset($sim['net_win_rate_pct']) ? round($sim['net_win_rate_pct'], 1) : '-', round((float) ($sim['total_net_pct'] ?? 0), 2)));
            $this->line(sprintf('   baselines on the same holdout: always-buy %.2f%%, always-sell %.2f%%',
                (float) data_get($r, 'baselines.always_buy.total_net_pct', 0), (float) data_get($r, 'baselines.always_sell.total_net_pct', 0)));
            foreach (array_slice($r['features_at_entry_vs_holdout'] ?? [], 0, 6) as $f) {
                $this->line(sprintf('   %-18s entry %.5f vs holdout %.5f (z %+.2f)', $f['feature'], $f['entry_mean'], $f['holdout_mean'], $f['z_shift']));
            }
        }
        $this->line('Findings only - no inverted strategy is derived from these signals.');

        return self::SUCCESS;
    }
}
