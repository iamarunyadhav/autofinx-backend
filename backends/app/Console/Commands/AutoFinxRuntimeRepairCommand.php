<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AutoFinxRuntimeRepairCommand extends Command
{
    protected $signature = 'autofinx:runtime-repair {--apply : Apply scoped application-level cleanup}';

    protected $description = 'Validate and repair AutoFinix runtime state without system-level process changes.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'AUTOFINX RUNTIME REPAIR APPLY' : 'AUTOFINX RUNTIME REPAIR DRY RUN');

        $configExit = Artisan::call('autofinx:config-doctor');
        $this->output->write(Artisan::output());

        $cleanupExit = Artisan::call('autofinx:market-feed:cleanup', ['--apply' => $apply]);
        $this->output->write(Artisan::output());

        $healthExit = Artisan::call('autofinx:runtime-health');
        $this->output->write(Artisan::output());

        return max($configExit, $cleanupExit, $healthExit);
    }
}
