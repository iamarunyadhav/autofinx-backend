<?php

namespace App\Domain\Prediction\Providers;

use App\Domain\Prediction\Contracts\PredictionProviderInterface;
use App\Domain\Prediction\Data\PredictionRequest;
use App\Domain\Prediction\Data\PredictionResult;

final class MarketStructurePredictionProvider implements PredictionProviderInterface
{
    public function key(): string { return 'market_structure'; }
    public function version(): string { return 'closed-candle-structure-v1'; }

    public function predict(PredictionRequest $request): PredictionResult
    {
        $candles = array_values($request->candles);
        if (count($candles) < 8) {
            return PredictionResult::unavailable($request, $this->key(), $this->version(), 'insufficient_candles');
        }
        $first = (float) ($candles[0]['open'] ?? 0);
        $last = (float) ($candles[array_key_last($candles)]['close'] ?? 0);
        if ($first <= 0 || $last <= 0) {
            return PredictionResult::unavailable($request, $this->key(), $this->version(), 'invalid_candle_prices');
        }
        $movePct = (($last / $first) - 1) * 100;
        $score = max(-1, min(1, $movePct / 1.0));
        $direction = $score >= 0.2 ? 'BUY' : ($score <= -0.2 ? 'SELL' : 'NEUTRAL');
        $confidence = 50 + abs($score) * 45;

        return new PredictionResult(
            provider: $this->key(), providerVersion: $this->version(), generatedAt: now()->toIso8601String(),
            symbol: $request->symbol, marketType: $request->marketType, timeframe: $request->timeframe,
            horizonSeconds: $request->horizonSeconds, available: true,
            currentPrice: $request->currentQuote ?? $last, direction: $direction,
            buyProbability: $direction === 'BUY' ? $confidence / 100 : null,
            sellProbability: $direction === 'SELL' ? $confidence / 100 : null,
            neutralProbability: $direction === 'NEUTRAL' ? 1.0 : null,
            confidence: round($confidence, 2), uncertainty: round(100 - $confidence, 2),
            evidence: ['input_basis' => 'closed_candles', 'sample_size' => count($candles)],
            factorScores: ['move_pct' => round($movePct, 6), 'structure_score' => round($score, 6)],
            reason: 'Observable closed-candle structure baseline; not a trained model.', dataQuality: 'GOOD',
        );
    }
}
