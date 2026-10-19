<?php

namespace App\Domain\Ml\Shadow;

use App\Models\V5ResearchScannerRun;
use Illuminate\Support\Facades\Schema;

/**
 * Bounded hand-off from V5 discovery to the simulated-paper layer.
 * Missing, stale, fixture, or untrained candidates must never widen the
 * paper universe; the caller receives an empty list and opens no position.
 */
final class DynamicV5ResearchUniverse
{
    /** @return array{mode:string,symbols:list<string>,reason:string,as_of:?string,candidates:list<array<string,mixed>>} */
    public function current(): array
    {
        $configured = array_values((array) config('ml.symbols', []));
        if (! (bool) config('ml.autopilot.dynamic_universe_enabled', false)) {
            return ['mode' => 'configured_cohort', 'symbols' => $configured, 'reason' => 'DYNAMIC_UNIVERSE_DISABLED', 'as_of' => null, 'candidates' => []];
        }
        if (! Schema::hasTable('v5_research_scanner_runs')) {
            return ['mode' => 'v5_shortlist', 'symbols' => [], 'reason' => 'V5_SCANNER_TABLE_MISSING', 'as_of' => null, 'candidates' => []];
        }
        $run = V5ResearchScannerRun::query()->where('source_mode', 'binance_public')->with('candidates')->latest('as_of')->first();
        if (! $run) {
            return ['mode' => 'v5_shortlist', 'symbols' => [], 'reason' => 'NO_V5_SCAN_YET', 'as_of' => null, 'candidates' => []];
        }
        // `diffInSeconds` is absolute by default. A clock-skewed future run is
        // treated as fresh rather than accidentally becoming a negative age.
        $age = max(0, now('UTC')->diffInSeconds($run->as_of));
        if ($age > (int) config('ml.autopilot.dynamic_universe_max_age_seconds', 1800)) {
            return ['mode' => 'v5_shortlist', 'symbols' => [], 'reason' => 'V5_SHORTLIST_STALE', 'as_of' => $run->as_of?->toIso8601String(), 'candidates' => []];
        }
        $candidates = $run->candidates->sortBy('rank')->filter(function ($candidate) use ($configured): bool {
            return in_array($candidate->symbol, $configured, true)
                && (bool) data_get($candidate->evidence, 'v5_selection.eligible', false);
        })->map(fn ($candidate) => [
            'symbol' => $candidate->symbol, 'rank' => (int) $candidate->rank, 'score' => (float) $candidate->ranking_score,
            'reason' => data_get($candidate->evidence, 'v5_selection.reason'),
            'direction' => data_get($candidate->evidence, 'v5_selection.direction'),
            'volatility_15m' => data_get($candidate->evidence, 'v5_selection.volatility_15m'),
        ])->values()->all();

        return ['mode' => 'v5_shortlist', 'symbols' => array_values(array_column($candidates, 'symbol')),
            'reason' => $candidates === [] ? 'NO_MODEL_READY_QUALITY_CANDIDATE' : 'FRESH_QUALITY_SHORTLIST',
            'as_of' => $run->as_of?->toIso8601String(), 'candidates' => $candidates];
    }
}
