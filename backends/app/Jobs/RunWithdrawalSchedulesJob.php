<?php

namespace App\Jobs;

use App\Domain\Billing\AutoWithdrawalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunWithdrawalSchedulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly ?string $workspaceId = null)
    {
        $this->onQueue('billing');
    }

    public function handle(AutoWithdrawalService $service): void
    {
        $service->runDue($this->workspaceId, 50);
    }
}

