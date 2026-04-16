<?php

namespace App\Jobs;

use App\Domain\Portfolio\PortfolioOptimizerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunPortfolioOptimizerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'risk';

    public function __construct(public string $workspaceId, public string $strategy = 'fixed')
    {
    }

    public function handle(PortfolioOptimizerService $service): void
    {
        $service->run($this->workspaceId, $this->strategy);
    }
}
