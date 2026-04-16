<?php

namespace Services\MarketService\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Services\MarketService\Events\MarketTickGenerated;

class GenerateDemoTick implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'market';

    public function __construct(public string $symbol)
    {
    }

    public function handle(): void
    {
        $price = round(mt_rand(1000, 60000) / 100, 2);
        $volume = round(mt_rand(1, 1000) / 10, 2);
        MarketTickGenerated::dispatch(
            $this->symbol,
            $price,
            $volume,
            now()
        );
    }
}
