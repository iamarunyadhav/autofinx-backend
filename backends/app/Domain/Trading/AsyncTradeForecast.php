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
        // Exact inputs and actor scope prevent cross-selection reuse. Bounded
        // result/lock lifetimes allow recovery if a worker disappears.
        $key = 'trade-forecast:v1:'.hash('sha256', json_encode([$actor, $kind, $data], JSON_THROW_ON_ERROR));
        $cache = Cache::store('redis');
        $result = $cache->get($key);
        if (is_array($result)) {
            return response()->json($result);
        }
        $lock = $cache->lock($key.':pending', 45);
        if ($lock->get()) {
            try {
                Bus::dispatch((new CalculateTradeForecast($key, $lock->owner(), $kind, $data, time()))
                    ->onConnection('redis')->onQueue('predictions'));
            } catch (Throwable $e) {
                $lock->release();
                throw $e;
            }
        }

        return response()->json(['status' => 'calculating', 'retry_after_ms' => 1000], 202);
    }
}
