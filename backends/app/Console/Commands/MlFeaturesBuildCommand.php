<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\FeatureLabelService;

final class MlFeaturesBuildCommand extends MlShadowCommand
{
    protected $signature = 'ml:features:build {--symbol=*} {--full : Rescan the full candle history}';

    protected $description = 'Build causal feature rows and per-horizon labels from validated candles, then mature labels (shadow ML only).';

    public function handle(FeatureLabelService $features): int
    {
        foreach ($this->symbols() as $symbol) {
            $built = $features->build($symbol, (bool) $this->option('full'));
            $matured = $features->mature($symbol);
            $this->info(sprintf('%s: feature rows +%d, labels +%d, matured %s', $symbol, $built['feature_rows'], $built['labels'], json_encode($matured)));
        }

        return self::SUCCESS;
    }
}
