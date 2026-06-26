<?php

namespace App\Console\Commands;

use App\Domain\Ops\TradingRuntimeHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class FutureTradeDoctorCommand extends Command
{
    protected $signature = 'future-trade:doctor {--workspace=} {--json}';

    protected $description = 'Run Future Trade runtime diagnostics and fail clearly when required workers, queues, or feeds are unhealthy.';

    public function handle(TradingRuntimeHealthService $health): int
    {
        $workspaceId = trim((string) $this->option('workspace')) ?: null;
        $snapshot = $health->snapshot($workspaceId);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('<options=bold>Future Trade Doctor</>');
            $this->line('Overall: '.$snapshot['overall']);
            foreach ((array) ($snapshot['reasons'] ?? []) as $reason) {
                $this->line(' - '.$reason);
            }
            $this->newLine();
            Artisan::call('future-trade:runtime-status', array_filter([
                '--workspace' => $workspaceId,
            ]));
            $this->line(Artisan::output());
        }

        return ($snapshot['overall'] ?? 'red') === 'red' ? self::FAILURE : self::SUCCESS;
    }
}
