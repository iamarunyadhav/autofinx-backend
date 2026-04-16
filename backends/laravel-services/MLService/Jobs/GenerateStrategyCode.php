<?php

namespace Services\MLService\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateStrategyCode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'ml';

    public function __construct(public string $prompt)
    {
    }

    public function handle(): void
    {
        // Stub: return a canned strategy snippet or store somewhere
    }
}
