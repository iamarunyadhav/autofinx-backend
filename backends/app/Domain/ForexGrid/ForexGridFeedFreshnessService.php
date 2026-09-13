<?php

namespace App\Domain\ForexGrid;

use Illuminate\Support\Carbon;

/**
 * Deliberately NOT a port of App\Domain\Ops\OperationalBreakerService.
 * OANDA is REST-polled here (no websocket), so there is no websocket-vs-
 * scheduled-job race for a breaker state machine to arbitrate: the SAME job
 * run that would detect staleness is the one evaluating right now. A
 * snapshot is only ever "stale" if the venue call that just happened failed
 * (handled by the job's own try/catch around getLatestPrice()) or if too
 * much wall-clock time has passed since the last successful evaluation
 * (checked here, purely from ForexGridBotState.last_evaluated_at -- no
 * separate cache/Redis key namespace needed for this module).
 */
class ForexGridFeedFreshnessService
{
    public function isStale(?Carbon $lastEvaluatedAt, int $maxAgeSeconds = 120): bool
    {
        if ($lastEvaluatedAt === null) {
            return false;
        }

        return $lastEvaluatedAt->copy()->addSeconds($maxAgeSeconds)->isPast();
    }
}
