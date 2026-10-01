<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\ShadowEvaluationService;

final class MlShadowEvaluateCommand extends MlShadowCommand
{
    protected $signature = 'ml:shadow:evaluate {--force}';

    protected $description = 'Score matured shadow predictions against the real T+h closed candle.';

    public function handle(ShadowEvaluationService $evaluation): int
    {
        if ($this->disabled()) {
            return self::SUCCESS;
        }
        $result = $evaluation->score();
        $this->line("Scored {$result['scored']}; unscorable {$result['unscorable']}.");

        return self::SUCCESS;
    }
}
