<?php

namespace App\Domain\MarketContext;

use App\Domain\MarketContext\Support\ProfileRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Small, scalar-only projection of the latest stored snapshots for the Nexus
 * observation. Read-only and failure-safe: it must never break a Nexus cycle.
 */
class MarketContextObservation
{
    public function __construct(private readonly SnapshotStore $store, private readonly ProfileRegistry $profiles) {}

    public function summary(): array
    {
        if (! (bool) config('market_context.enabled', false)) {
            return ['status' => 'DISABLED'];
        }
        try {
            if (! Schema::hasTable('mctx_snapshots')) {
                return ['status' => 'ADAPTER_REQUIRED'];
            }
            $instruments = [];
            foreach ($this->profiles->enabled() as $instrument => $profile) {
                $row = $this->store->latest($profile['asset_class'], $instrument);
                if ($row === null) {
                    $instruments[$instrument] = ['status' => 'NO_SNAPSHOT'];

                    continue;
                }
                $p = (array) $row->payload;
                $instruments[$instrument] = ['status' => 'OBSERVED', 'as_of' => $row->as_of->toIso8601String(),
                    'freshness' => $p['freshness']['state'] ?? null, 'risk_level' => $p['risk']['level'] ?? null,
                    'veto_hint' => (bool) ($p['risk']['veto_hint'] ?? false), 'flags' => array_slice((array) ($p['risk']['flags'] ?? []), 0, 6),
                    'sentiment_score' => $p['sentiment']['score'] ?? null];
            }

            return ['status' => 'OBSERVED', 'origin' => 'LOCAL_COMPUTATION', 'instruments' => $instruments];
        } catch (Throwable $e) {
            Log::warning('market_context.observation_failed', ['error' => get_class($e)]);

            return ['status' => 'UNAVAILABLE'];
        }
    }
}
