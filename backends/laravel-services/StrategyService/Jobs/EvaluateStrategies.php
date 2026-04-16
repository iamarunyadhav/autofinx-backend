<?php

namespace Services\StrategyService\Jobs;

use App\Models\StrategyInstance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Services\RiskService\Jobs\EvaluateRisk;
use Services\StrategyService\Support\Signals\SignalCalculator;

class EvaluateStrategies implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'strategies';

    public function __construct(
        public string $symbol,
        public float $price,
        public \DateTimeInterface $timestamp
    ) {
    }

    public function handle(): void
    {
        $instances = StrategyInstance::with(['strategy', 'params'])
            ->where('status', 'running')
            ->get();

        foreach ($instances as $instance) {
            $signal = SignalCalculator::for($instance)->calculate($this->price, $this->timestamp);

            if ($signal->action !== 'HOLD') {
                EvaluateRisk::dispatch($instance->id, $signal, [
                    'price' => $this->price,
                    'symbol' => $this->symbol,
                    'timestamp' => $this->timestamp,
                    'mode' => $instance->mode === 'live' ? 'live' : 'paper',
                    'connection_id' => $instance->mode === 'live' ? $instance->connection_id : null,
                ]);
            }
        }
    }
}
