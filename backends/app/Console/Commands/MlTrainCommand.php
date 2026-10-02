<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Shadow\RetrainEligibility;
use Throwable;

final class MlTrainCommand extends MlShadowCommand
{
    protected $signature = 'ml:train {symbol} {horizon : Horizon in minutes}
        {--type= : side_classifier or regression (default: every type in ml.training.candidate_types)}
        {--ignore-eligibility : Train even without enough new labels}';

    protected $description = 'Run shadow training attempt(s) now (candidate -> promote/reject). Never touches live trading.';

    public function handle(MlShadowTrainer $trainer, RetrainEligibility $eligibility, FeatureLabelService $features): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $horizon = (int) $this->argument('horizon');
        if (! in_array($symbol, (array) config('ml.symbols', []), true) || ! in_array($horizon, $features->horizons(), true)) {
            $this->error('Symbol/horizon not configured in config/ml.php (horizons: '.implode(',', $features->horizons()).').');

            return self::FAILURE;
        }
        $types = $this->option('type') ? [(string) $this->option('type')] : MlShadowTrainer::configuredTypes();
        if (array_diff($types, MlShadowTrainer::TYPES) !== []) {
            $this->error('--type must be one of: '.implode(', ', MlShadowTrainer::TYPES));

            return self::FAILURE;
        }
        $check = $eligibility->check($symbol, $horizon);
        if (! $check['eligible'] && ! $this->option('ignore-eligibility')) {
            $this->warn("Not eligible: {$check['reason']} (mature {$check['mature_total']}, new {$check['new_mature']}, required {$check['required']}).");

            return self::FAILURE;
        }

        $failed = false;
        foreach ($types as $type) {
            try {
                $model = $trainer->train($symbol, $horizon, $type);
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("{$symbol} h{$horizon} {$type}: training failed (champion unchanged): ".$exception->getMessage());

                continue;
            }
            // First line per attempt is the one-line verdict (log-friendly).
            $this->info("{$symbol} h{$horizon} {$type} {$model->model_version}: {$model->status} - {$model->decision_reason}");
            $holdout = (array) data_get($model->metrics, 'holdout', []);
            $this->line((string) json_encode([
                'non_overlapping' => $holdout['non_overlapping'] ?? null,
                'baselines' => $holdout['baselines'] ?? null,
                'effective_samples' => $holdout['effective_samples'] ?? null,
                'folds' => array_map(static fn ($fold) => [
                    'net_pct' => data_get($fold, 'non_overlapping.total_net_pct'),
                    'trades' => data_get($fold, 'non_overlapping.trades'),
                    'always_buy_net_pct' => data_get($fold, 'baselines.always_buy.total_net_pct'),
                    'always_sell_net_pct' => data_get($fold, 'baselines.always_sell.total_net_pct'),
                ], (array) data_get($model->metrics, 'walk_forward.folds', [])),
                'comparison' => $model->comparison,
            ], JSON_PRETTY_PRINT));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
