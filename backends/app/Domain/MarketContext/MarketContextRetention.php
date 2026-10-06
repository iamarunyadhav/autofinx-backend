<?php

namespace App\Domain\MarketContext;

use App\Domain\MarketContext\Support\UtcTime;
use App\Models\MarketContextEvent;
use App\Models\MarketContextMetric;
use App\Models\MarketContextSnapshot;
use Illuminate\Support\Carbon;

/** Chunked, bounded deletes. Normalized rows outlive the bounded excerpt text. */
class MarketContextRetention
{
    /** @return array{events:int, excerpts:int, metrics:int, snapshots:int} */
    public function prune(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->utc();
        $cfg = (array) config('market_context.retention');
        $chunk = max(50, (int) ($cfg['chunk'] ?? 500));

        $excerpts = 0;
        do {
            $ids = MarketContextEvent::query()->whereNotNull('excerpt')
                ->where('first_seen_at', '<', $now->copy()->subDays((int) $cfg['excerpt_days']))->limit($chunk)->pluck('id');
            if ($ids->isNotEmpty()) {
                $excerpts += MarketContextEvent::query()->whereIn('id', $ids)->update(['excerpt' => null, 'updated_at' => UtcTime::db($now)]);
            }
        } while ($ids->count() === $chunk);

        return ['events' => $this->deleteOlder(MarketContextEvent::class, 'first_seen_at', $now->copy()->subDays((int) $cfg['events_days']), $chunk),
            'excerpts' => $excerpts,
            'metrics' => $this->deleteOlder(MarketContextMetric::class, 'first_seen_at', $now->copy()->subDays((int) $cfg['metrics_days']), $chunk),
            'snapshots' => $this->deleteOlder(MarketContextSnapshot::class, 'as_of', $now->copy()->subDays((int) $cfg['snapshots_days']), $chunk)];
    }

    private function deleteOlder(string $model, string $column, Carbon $cutoff, int $chunk): int
    {
        $deleted = 0;
        do {
            $ids = $model::query()->where($column, '<', $cutoff)->limit($chunk)->pluck('id');
            if ($ids->isNotEmpty()) {
                $deleted += $model::query()->whereIn('id', $ids)->delete();
            }
        } while ($ids->count() === $chunk);

        return $deleted;
    }
}
