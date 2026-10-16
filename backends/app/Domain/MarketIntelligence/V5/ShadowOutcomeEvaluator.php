<?php

namespace App\Domain\MarketIntelligence\V5;

use DateTimeImmutable;
use InvalidArgumentException;

/** Pure evaluator: callers must provide a candle known after the horizon. */
final class ShadowOutcomeEvaluator
{
    public function evaluate(ShadowPrediction $prediction, string $outcomeAt, float $actualMove): array
    {
        $available = new DateTimeImmutable($prediction->outcomeAvailableAt);
        $actual = new DateTimeImmutable($outcomeAt);
        if ($actual < $available) {
            throw new InvalidArgumentException('Future outcome is not available yet.');
        }
        $direction = $actualMove > 0 ? 'UP' : ($actualMove < 0 ? 'DOWN' : 'SIDEWAYS');
        return [
            'actual_direction' => $direction,
            'actual_move' => $actualMove,
            'correct' => $direction === $prediction->direction,
            'evaluated_at' => $outcomeAt,
            'outcome_status' => 'evaluated',
            'execution_authorized' => false,
        ];
    }
}
