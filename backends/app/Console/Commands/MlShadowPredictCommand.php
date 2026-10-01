<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\ShadowPredictionService;

final class MlShadowPredictCommand extends MlShadowCommand
{
    protected $signature = 'ml:shadow:predict {--symbol=*} {--force}';

    protected $description = 'Store one shadow prediction per symbol x horizon for the newest closed anchor (idempotent).';

    public function handle(ShadowPredictionService $predictions, FeatureLabelService $features): int
    {
        if ($this->disabled()) {
            return self::SUCCESS;
        }
        foreach ($this->symbols() as $symbol) {
            foreach ($features->horizons() as $horizon) {
                $result = $predictions->predictLatest($symbol, $horizon);
                $p = $result['prediction'];
                $this->line(sprintf('%s h%d: %s%s', $symbol, $horizon, $result['status'],
                    $p ? sprintf(' - %s %s (model %s)', $p->decision, $p->decision_reason, $p->model_version) : ''));
            }
        }

        return self::SUCCESS;
    }
}
