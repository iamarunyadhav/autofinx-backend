<?php

namespace App\Jobs;

use App\Domain\Ops\OperationalBreakerService;
use App\Models\MarketTick;
use App\Models\MarketFeedStatus;
use App\Models\RiskEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DetectStaleFeedsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $thresholdSeconds;

    public function __construct(int $thresholdSeconds = 60)
    {
        $this->onQueue('monitoring');
        $this->thresholdSeconds = $thresholdSeconds;
    }

    public function handle(OperationalBreakerService $breakers): void
    {
        $now = now();
        $latestTick = MarketTick::orderByDesc('occurred_at')->first();
        $latestTickAt = $latestTick?->occurred_at;
        $globalFeedIsStale = $latestTickAt !== null
            && $latestTickAt->lt($now->copy()->subSeconds($this->thresholdSeconds));

        if ($globalFeedIsStale) {
            $breakers->activateStaleFeedBreaker(null, [
                'last_tick_at' => $latestTickAt?->toIso8601String(),
                'threshold_seconds' => $this->thresholdSeconds,
            ]);
        } else {
            $breakers->clearStaleFeedBreaker(null, [
                'last_tick_at' => $latestTickAt?->toIso8601String(),
                'threshold_seconds' => $this->thresholdSeconds,
            ]);
        }

        if (! Schema::hasTable('market_feed_status')) {
            return;
        }

        $stale = MarketFeedStatus::whereNotNull('last_tick_at')
            ->where('last_tick_at', '<', $now->copy()->subSeconds($this->thresholdSeconds))
            ->get();

        foreach ($stale as $row) {
            RiskEvent::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $row->workspace_id,
                'type' => 'feed_stale',
                'severity' => 'warning',
                'reason_json' => [
                    'symbol_id' => $row->symbol_id,
                    'last_tick_at' => $row->last_tick_at,
                    'threshold_seconds' => $this->thresholdSeconds,
                ],
                'triggered_at' => $now,
            ]);
        }
    }
}
