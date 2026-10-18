<?php

namespace App\Domain\MarketIntelligence\V5\Application;

use App\Domain\MarketIntelligence\V5\ShadowOutcomeEvaluator;
use App\Domain\MarketIntelligence\V5\ShadowPrediction;
use App\Models\V5MarketObservation;
use App\Models\V5ShadowPrediction as StoredShadowPrediction;
use Carbon\CarbonInterface;

final class MaturedOutcomeEvaluationService
{
    public function evaluate(CarbonInterface $asOf, int $limit = 500): array
    {
        $evaluated = 0;
        $awaitingObservation = 0;
        $invalidReference = 0;

        StoredShadowPrediction::query()
            ->where('outcome_status', 'pending')
            ->where('outcome_available_at', '<=', $asOf)
            ->orderBy('outcome_available_at')
            ->limit($limit)
            ->get()
            ->each(function (StoredShadowPrediction $stored) use (&$evaluated, &$awaitingObservation, &$invalidReference): void {
                $referencePrice = (float) ($stored->reference_price
                    ?? data_get($stored->evidence, 'horizons.15m.reference_close', 0));

                if ($referencePrice <= 0) {
                    $stored->update([
                        'outcome_status' => 'unavailable',
                        'data_status' => 'missing_reference_price',
                        'evaluated_at' => now(),
                    ]);
                    $invalidReference++;

                    return;
                }

                $availableMs = $stored->outcome_available_at->getTimestamp() * 1000;
                $observation = V5MarketObservation::query()
                    ->where('symbol', $stored->symbol)
                    ->where('timeframe', '15m')
                    ->where('candle_close_ms', '>=', $availableMs)
                    ->where('candle_close_ms', '<=', $availableMs + (15 * 60 * 1000))
                    ->orderBy('candle_close_ms')
                    ->first();

                if (! $observation) {
                    $awaitingObservation++;

                    return;
                }

                $outcomePrice = (float) $observation->close;
                $actualMove = ($outcomePrice / $referencePrice) - 1.0;
                $threshold = max(
                    0.001,
                    ((float) data_get($stored->evidence, 'horizons.15m.volatility', 0)) * 0.25
                );
                $prediction = new ShadowPrediction(
                    $stored->symbol,
                    $stored->horizon,
                    (int) $stored->horizon_minutes,
                    $stored->forecast_at->toAtomString(),
                    $stored->outcome_available_at->toAtomString(),
                    $stored->direction,
                    $stored->expected_move,
                    $stored->probabilities,
                    (bool) $stored->probability_calibrated,
                    $stored->evidence ?? [],
                    $stored->model_version,
                    $stored->data_status,
                    $stored->model_status,
                );
                $result = (new ShadowOutcomeEvaluator)->evaluate(
                    $prediction,
                    $observation->observed_at->toAtomString(),
                    $actualMove,
                    $threshold
                );

                $updated = StoredShadowPrediction::query()
                    ->whereKey($stored->id)
                    ->where('outcome_status', 'pending')
                    ->update([
                        'actual_direction' => $result['actual_direction'],
                        'actual_move' => $result['actual_move'],
                        'correct' => $result['correct'],
                        'evaluated_at' => $result['evaluated_at'],
                        'outcome_status' => $result['outcome_status'],
                        'outcome_price' => $outcomePrice,
                        'outcome_observed_at' => $observation->observed_at,
                        'updated_at' => now(),
                    ]);

                $evaluated += $updated;
            });

        return compact('evaluated', 'awaitingObservation', 'invalidReference');
    }
}
