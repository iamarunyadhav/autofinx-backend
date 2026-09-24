<?php

namespace App\Jobs;

use App\Domain\Ops\PredictionQueueLatencyMetrics;
use App\Domain\Trading\QuickTradeForecastCalculator;
use App\Domain\Trading\TradeForecastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class CalculateTradeForecast implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 20;
    public int $tries = 1;

    public function __construct(
        public string $key,
        public string $pendingKey,
        public string $lockOwner,
        public string $kind,
        public array $data,
        public int $requestedAt,
    ) {}

    public function handle(QuickTradeForecastCalculator $calculator, TradeForecastService $forecast): void
    {
        $cache = Cache::store('redis');
        $startedAt = (int) floor(microtime(true) * 1000);
        $queueWaitMs = max(0, $startedAt - ($this->requestedAt * 1000));
        try {
            // Expired inputs never become a newly generated trading signal.
            if (time() - $this->requestedAt >= 20) {
                // N2: count what the 20s rule throws away. Observational only;
                // record() never throws.
                app(PredictionQueueLatencyMetrics::class)->record(
                    PredictionQueueLatencyMetrics::OUTCOME_DROPPED_BEFORE_COMPUTE, $queueWaitMs, null, $this->kind);

                return;
            }
            $result = match ($this->kind) {
                'forecast' => $calculator->forecast($this->data, $forecast),
                'comparison' => $calculator->comparison($this->data, $forecast),
            };
            $computeMs = max(0, (int) floor(microtime(true) * 1000) - $startedAt);
            $remaining = 20 - (time() - $this->requestedAt);
            app(PredictionQueueLatencyMetrics::class)->record(
                $remaining > 0
                    ? PredictionQueueLatencyMetrics::OUTCOME_PUBLISHED
                    : PredictionQueueLatencyMetrics::OUTCOME_DROPPED_AFTER_COMPUTE,
                $queueWaitMs,
                $computeMs,
                $this->kind,
            );
            if ($remaining > 0) {
                $result['calculation'] = [
                    'requested_at' => $this->requestedAt,
                    'generated_at' => time(),
                    'valid_until' => $this->requestedAt + 20,
                    'queue_wait_ms' => $queueWaitMs,
                    'compute_ms' => $computeMs,
                    'symbol' => $this->data['symbol'] ?? null,
                    'exchange' => $this->data['exchange'] ?? null,
                    'timeframe' => $this->data['timeframe'],
                    'expiry_seconds' => $this->data['expiry_seconds'],
                ];
                $cache->put($this->key, $result, $remaining);
            }
        } finally {
            $cache->restoreLock($this->pendingKey, $this->lockOwner)->release();
        }
    }
}
