<?php

namespace App\Domain\MarketData;

use App\Domain\MarketData\Enums\FeedState;
use App\Domain\MarketData\Enums\StreamType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MarketRecoveryManager
{
    public function __construct(private readonly MarketStateStore $store)
    {
    }

    public function requestRecovery(string $provider, string $instrument, StreamType $stream = StreamType::QUOTE, string $reason = 'stale_detected', array $diagnostics = []): array
    {
        $provider = strtolower($provider);
        $instrument = strtoupper($instrument);
        $lock = Cache::lock($this->store->recoveryLockKey($provider, $instrument, $stream), max(5, (int) config('market_data.recovery.lock_seconds', 30)));

        if (! $lock->get()) {
            return $this->store->transition($provider, $instrument, $stream, FeedState::RECOVERING, 'recovery_already_in_progress', $diagnostics);
        }

        try {
            $state = $this->store->transition($provider, $instrument, $stream, FeedState::RECOVERING, $reason, array_merge($diagnostics, [
                'recovery_id' => (string) Str::uuid(),
                'started_at' => now()->toIso8601String(),
                'algorithm' => 'distributed_lock_then_long_lived_gateway_reconnects',
            ]));

            Log::warning('[MarketData] Recovery requested', ['provider' => $provider, 'instrument' => $instrument, 'stream_type' => $stream->value, 'reason' => $reason, 'diagnostics' => $diagnostics]);

            return $state;
        } finally {
            $lock->release();
        }
    }
}
