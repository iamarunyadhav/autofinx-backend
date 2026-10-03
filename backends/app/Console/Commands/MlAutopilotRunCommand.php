<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\MlPaperAutopilotService;

final class MlAutopilotRunCommand extends MlShadowCommand
{
    protected $signature = 'ml:autopilot:run {--force}';

    protected $description = 'ML Paper Autopilot: open simulated trades on new champion BUY/SELL signals and settle expired ones (separate table; never an order).';

    public function handle(MlPaperAutopilotService $autopilot): int
    {
        if ($this->disabled()) {
            return self::SUCCESS;
        }
        if (! (bool) config('ml.autopilot.enabled', true) && ! $this->option('force')) {
            $this->line('ML Paper Autopilot is disabled (AUTOFINX_ML_PAPER_AUTOPILOT_ENABLED=false); skipped.');

            return self::SUCCESS;
        }
        $result = $autopilot->run();
        $this->line("Autopilot: opened {$result['opened']}, settled {$result['settled']}, cancelled {$result['cancelled']}.");

        return self::SUCCESS;
    }
}
