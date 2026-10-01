<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\MlModelRegistry;

final class MlModelRollbackCommand extends MlShadowCommand
{
    protected $signature = 'ml:model:rollback {symbol} {horizon} {--apply : Perform the rollback (default is a dry run)}';

    protected $description = 'Re-instate the previous shadow champion for a symbol x horizon.';

    public function handle(MlModelRegistry $registry): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $horizon = (int) $this->argument('horizon');
        $current = $registry->champion($symbol, $horizon);
        $this->line('Current champion: '.($current?->model_version ?? 'none'));
        if (! $this->option('apply')) {
            $this->line('Dry run. Re-run with --apply to roll back.');

            return self::SUCCESS;
        }
        $model = $registry->rollback($symbol, $horizon);
        $this->info("Champion is now {$model->model_version}.");

        return self::SUCCESS;
    }
}
