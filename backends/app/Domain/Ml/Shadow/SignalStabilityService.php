<?php

namespace App\Domain\Ml\Shadow;

use App\Models\MlShadowPrediction;
use Illuminate\Support\Collection;

/**
 * Turns the raw 5-minute decisions of one symbol × horizon into a STABLE
 * signal, so a probability hovering around the floor (55% BUY, 54% WAIT,
 * 56% BUY…) does not flicker on screen or open trades.
 *
 * Hysteresis, replayed over the recent stored predictions (deterministic, no
 * extra state table; recomputable at any time):
 *   - enter BUY/SELL only after `confirm_predictions` consecutive raw
 *     decisions for that side;
 *   - stay in it while that side's probability is >= `exit_probability`
 *     (below the entry floor), leave to WAIT after `confirm_predictions`
 *     consecutive readings under it;
 *   - flip to the opposite side only on its own confirmed run;
 *   - a gap in the prediction stream (stale data) resets to WAIT.
 *
 * Display + Paper Autopilot only. Never read by any live order path.
 */
class SignalStabilityService
{
    /**
     * @return array{state:string, since:?string, held_minutes:?int, confirming:int, raw_flips:int,
     *               window_predictions:int, latest:?MlShadowPrediction}
     */
    public function state(string $symbol, int $horizon): array
    {
        $rows = MlShadowPrediction::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)
            ->orderByDesc('anchor_time_ms')->limit(max(4, (int) config('ml.stability.lookback_predictions', 48)))
            ->get()->reverse()->values();

        return $this->replay($rows);
    }

    /** @param Collection<int, MlShadowPrediction> $rows oldest first */
    public function replay(Collection $rows): array
    {
        $confirm = max(1, (int) config('ml.stability.confirm_predictions', 2));
        $exitProbability = (float) config('ml.stability.exit_probability', 0.50);
        $maxGapMs = (int) config('ml.base_interval_seconds', 300) * 1000 * 3;

        $state = 'WAIT';
        $sinceMs = null;
        $run = ['side' => null, 'count' => 0, 'startMs' => null];
        $weak = 0;
        $flips = 0;
        $previousDecision = null;
        $previousMs = null;

        foreach ($rows as $row) {
            $t = (int) $row->anchor_time_ms;
            $decision = in_array($row->decision, ['BUY', 'SELL'], true) ? $row->decision : 'WAIT';
            if ($previousMs !== null && $t - $previousMs > $maxGapMs) {
                $state = 'WAIT';
                $sinceMs = $t;
                $run = ['side' => null, 'count' => 0, 'startMs' => null];
                $weak = 0;
            }
            if ($previousDecision !== null && $decision !== $previousDecision) {
                $flips++;
            }
            $previousDecision = $decision;
            $previousMs = $t;

            // Consecutive run of the same raw side.
            if ($decision !== 'WAIT' && $decision === $run['side']) {
                $run['count']++;
            } else {
                $run = $decision === 'WAIT' ? ['side' => null, 'count' => 0, 'startMs' => null] : ['side' => $decision, 'count' => 1, 'startMs' => $t];
            }

            if ($run['side'] !== null && $run['side'] !== $state && $run['count'] >= $confirm) {
                $state = $run['side'];      // confirmed entry or flip
                $sinceMs = $run['startMs'];
                $weak = 0;

                continue;
            }
            if ($state !== 'WAIT') {
                $probability = (float) ($state === 'BUY' ? $row->p_buy : $row->p_sell);
                $weak = ($row->p_buy === null && $row->p_sell === null)
                    ? ($decision === $state ? 0 : $weak + 1)        // regression model: no probabilities
                    : ($probability >= $exitProbability ? 0 : $weak + 1);
                if ($weak >= $confirm) {
                    $state = 'WAIT';
                    $sinceMs = $t;
                    $weak = 0;
                }
            } elseif ($sinceMs === null) {
                $sinceMs = $t;
            }
        }

        $latest = $rows->last();
        $nowMs = now('UTC')->getTimestampMs();

        return [
            'state' => $state,
            'since' => $sinceMs !== null ? gmdate('c', intdiv($sinceMs, 1000)) : null,
            'held_minutes' => $sinceMs !== null ? max(0, intdiv($nowMs - $sinceMs, 60_000)) : null,
            // Raw decisions in a row that agree with the current raw side.
            'confirming' => $run['count'],
            'raw_flips' => $flips,
            'window_predictions' => $rows->count(),
            'latest' => $latest,
        ];
    }
}
