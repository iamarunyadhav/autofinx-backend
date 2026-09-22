<?php

namespace App\Domain\Trading;

use App\Jobs\CalculateTradeForecast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;
use Throwable;

class AsyncTradeForecast
{
    public function request(string $actor, string $kind, array $data)
    {
        // Exact inputs identify a cacheable response, while the pending lock
        // intentionally excludes candle contents. A fast kline stream can
        // otherwise enqueue one expensive prediction per tick. One scope has
        // at most one worker; the UI's next poll submits the newest context
        // after that worker releases its lock (latest-wins, no stale backlog).
        $key = 'trade-forecast:v1:'.hash('sha256', json_encode([$actor, $kind, $data], JSON_THROW_ON_ERROR));
        $scope = [
            $actor,
            $kind,
            strtoupper((string) ($data['symbol'] ?? '')),
            strtolower((string) ($data['exchange'] ?? '')),
            strtolower((string) ($data['timeframe'] ?? '')),
            (int) ($data['expiry_seconds'] ?? 0),
            (float) ($data['stake_amount'] ?? 0),
            strtolower((string) ($data['bot_type'] ?? '')),
        ];
        $pendingKey = 'trade-forecast:pending:v1:'.hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
        $cache = Cache::store('redis');
        $result = $cache->get($key);
        if (is_array($result)) {
            return response()->json($result);
        }
        $lock = $cache->lock($pendingKey, 45);
        if ($lock->get()) {
            try {
                Bus::dispatch((new CalculateTradeForecast($key, $pendingKey, $lock->owner(), $kind, $data, time()))
                    ->onConnection('redis')->onQueue('predictions'));
            } catch (Throwable $e) {
                $lock->release();
                throw $e;
            }
        }

        return response()->json(['status' => 'calculating', 'retry_after_ms' => 1000], 202);
    }
}
