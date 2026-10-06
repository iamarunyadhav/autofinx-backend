<?php

namespace App\Domain\MarketContext;

use App\Domain\MarketContext\Data\ContextSnapshot;
use App\Models\MarketContextSnapshot;
use Illuminate\Support\Carbon;

/** Persists bucketed snapshots (mctx_snapshots). Re-running a bucket overwrites the same row. */
class SnapshotStore
{
    /** Floor to the configured bucket so the same asOf is reused (and a backtest can recompute it). */
    public function bucket(Carbon $at): Carbon
    {
        $step = max(1, (int) config('market_context.snapshot.bucket_minutes', 15)) * 60;
        $ts = $at->getTimestamp();

        return Carbon::createFromTimestampUTC($ts - ($ts % $step));
    }

    public function store(ContextSnapshot $snapshot): MarketContextSnapshot
    {
        return MarketContextSnapshot::query()->updateOrCreate(
            ['asset_class' => $snapshot->assetClass, 'instrument' => $snapshot->instrument,
                'as_of' => $snapshot->asOf, 'schema_version' => $snapshot->schemaVersion],
            ['payload' => $snapshot->toArray()],
        );
    }

    public function latest(string $assetClass, string $instrument): ?MarketContextSnapshot
    {
        return MarketContextSnapshot::query()->where('asset_class', $assetClass)->where('instrument', $instrument)
            ->orderByDesc('as_of')->first();
    }
}
