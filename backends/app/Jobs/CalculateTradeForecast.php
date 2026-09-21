<?php

namespace App\Jobs;

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
        public string $lockOwner,
        public string $kind,
        public array $data,
        public int $requestedAt,
    ) {}

    public function handle(QuickTradeForecastCalculator $calculator, TradeForecastService $forecast): void
    {
        $cache = Cache::store('redis');
        try {
            // Expired inputs never become a newly generated trading signal.
            if (time() - $this->requestedAt >= 20) {
                return;
            }
            $result = match ($this->kind) {
                'forecast' => $calculator->forecast($this->data, $forecast),
                'comparison' => $calculator->comparison($this->data, $forecast),
            };
            $remaining = 20 - (time() - $this->requestedAt);
            if ($remaining > 0) {
                $result['calculation'] = [
                    'requested_at' => $this->requestedAt,
                    'generated_at' => time(),
                    'valid_until' => $this->requestedAt + 20,
                    'symbol' => $this->data['symbol'] ?? null,
                    'exchange' => $this->data['exchange'] ?? null,
                    'timeframe' => $this->data['timeframe'],
                    'expiry_seconds' => $this->data['expiry_seconds'],
                ];
                $cache->put($this->key, $result, $remaining);
            }
        } finally {
            $cache->restoreLock($this->key.':pending', $this->lockOwner)->release();
        }
    }
}
