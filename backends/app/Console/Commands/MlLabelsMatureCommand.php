<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\FeatureLabelService;

final class MlLabelsMatureCommand extends MlShadowCommand
{
    protected $signature = 'ml:labels:mature {--symbol=*} {--force}';

    protected $description = 'Fill PENDING labels whose exact T+h closed candle now exists (shadow ML only).';

    public function handle(FeatureLabelService $features): int
    {
        if ($this->disabled()) {
            return self::SUCCESS;
        }
        foreach ($this->symbols() as $symbol) {
            $this->line($symbol.': matured '.json_encode($features->mature($symbol)));
        }

        return self::SUCCESS;
    }
}
