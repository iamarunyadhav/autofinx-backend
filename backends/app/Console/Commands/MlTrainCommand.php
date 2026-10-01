<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Shadow\RetrainEligibility;
use Throwable;

final class MlTrainCommand extends MlShadowCommand
{
    protected $signature = 'ml:train {symbol} {horizon : Horizon in minutes} {--ignore-eligibility : Train even without enough new labels}';

    protected $description = 'Run one shadow training attempt now (candidate -> promote/reject). Never touches live trading.';

    public function handle(MlShadowTrainer $trainer, RetrainEligibility $eligibility, FeatureLabelService $features): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $horizon = (int) $this->argument('horizon');
        if (! in_array($symbol, (array) config('ml.symbols', []), true) || ! in_array($horizon, $features->horizons(), true)) {
            $this->error('Symbol/horizon not configured in config/ml.php.');

            return self::FAILURE;
        }
        $check = $eligibility->check($symbol, $horizon);
        if (! $check['eligible'] && ! $this->option('ignore-eligibility')) {
            $this->warn("Not eligible: {$check['reason']} (mature {$check['mature_total']}, new {$check['new_mature']}, required {$check['required']}).");

            return self::FAILURE;
        }
        try {
            $model = $trainer->train($symbol, $horizon);
        } catch (Throwable $exception) {
            $this->error('Training failed (champion unchanged): '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->info("{$model->model_version}: {$model->status} - {$model->decision_reason}");
        $this->line((string) json_encode(['holdout' => data_get($model->metrics, 'holdout'), 'comparison' => $model->comparison], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
