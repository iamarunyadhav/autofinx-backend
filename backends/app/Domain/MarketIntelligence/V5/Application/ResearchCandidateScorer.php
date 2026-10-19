<?php

namespace App\Domain\MarketIntelligence\V5\Application;

/**
 * Scores an already-liquid, closed-candle V5 observation for paper research.
 * It deliberately measures data quality and market structure, never expected
 * profit. A score is a shortlist gate, not a trading signal.
 */
final class ResearchCandidateScorer
{
    /** @param array<string, mixed> $record @param array<string, mixed> $assessment */
    public function score(array $record, array $assessment): array
    {
        $horizons = (array) ($assessment['horizons'] ?? []);
        $windows = array_intersect_key($horizons, array_flip(['15m', '1h', '4h']));
        if (count($windows) !== 3 || collect($windows)->contains(fn (array $window) => ($window['status'] ?? null) !== 'observed')) {
            return $this->blocked('INSUFFICIENT_CLOSED_CANDLES');
        }

        $directions = array_map(static fn (array $window): string => (string) ($window['direction'] ?? 'SIDEWAYS'), $windows);
        $directional = array_values(array_filter($directions, static fn (string $direction): bool => in_array($direction, ['UP', 'DOWN'], true)));
        $dominant = $directional === [] ? 'SIDEWAYS' : (count(array_filter($directional, fn (string $d): bool => $d === 'UP')) >= count($directional) / 2 ? 'UP' : 'DOWN');
        $alignment = count(array_filter($directions, static fn (string $direction) => $direction === $dominant));
        $volatility = (float) data_get($windows, '15m.volatility', 0.0);
        $move = abs((float) data_get($windows, '15m.observed_move', 0.0));
        $minVolatility = (float) config('market_opportunity_brain.v5_research_universe.min_15m_volatility', 0.0008);
        $maxVolatility = (float) config('market_opportunity_brain.v5_research_universe.max_15m_volatility', 0.04);
        if ($volatility < $minVolatility) return $this->blocked('VOLATILITY_TOO_LOW', $dominant, $alignment, $volatility);
        if ($volatility > $maxVolatility) return $this->blocked('VOLATILITY_TOO_HIGH', $dominant, $alignment, $volatility);
        if ($alignment < (int) config('market_opportunity_brain.v5_research_universe.min_directional_timeframes', 2)) return $this->blocked('TIMEFRAME_CONFLICT', $dominant, $alignment, $volatility);

        $volumeScore = max(0.0, min(1.0, (float) ($record['opportunity_score'] ?? 0.0)));
        $trendScore = $alignment / 3;
        $movementScore = max(0.0, min(1.0, $move / max($volatility * 2, 0.000001)));
        // Prefer usable movement, but do not reward an extreme 15m range.
        $volatilityScore = $volatility <= 0.015 ? 1.0 : max(0.0, 1.0 - (($volatility - 0.015) / max(0.000001, $maxVolatility - 0.015)));
        $score = round(0.35 * $volumeScore + 0.30 * $trendScore + 0.20 * $movementScore + 0.15 * $volatilityScore, 6);
        $eligible = $score >= (float) config('market_opportunity_brain.v5_research_universe.min_quality_score', 0.45);

        return ['eligible' => $eligible, 'score' => $score, 'reason' => $eligible ? 'LIQUID_ALIGNED_CLOSED_CANDLE_SETUP' : 'QUALITY_SCORE_BELOW_GATE',
            'direction' => $dominant, 'directional_timeframes' => $alignment, 'volatility_15m' => $volatility,
            'components' => ['liquidity' => $volumeScore, 'trend_alignment' => $trendScore, 'movement_quality' => $movementScore, 'volatility_quality' => $volatilityScore]];
    }

    private function blocked(string $reason, string $direction = 'SIDEWAYS', int $alignment = 0, float $volatility = 0.0): array
    {
        return ['eligible' => false, 'score' => 0.0, 'reason' => $reason, 'direction' => $direction, 'directional_timeframes' => $alignment, 'volatility_15m' => $volatility, 'components' => []];
    }
}
