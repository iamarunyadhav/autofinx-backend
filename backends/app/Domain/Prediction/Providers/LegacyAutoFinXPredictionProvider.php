<?php

namespace App\Domain\Prediction\Providers;

use App\Domain\Prediction\Contracts\PredictionProviderInterface;
use App\Domain\Prediction\Data\PredictionRequest;
use App\Domain\Prediction\Data\PredictionResult;
use App\Domain\Trading\TradeForecastService;

final class LegacyAutoFinXPredictionProvider implements PredictionProviderInterface
{
    public function __construct(private readonly TradeForecastService $forecast)
    {
    }

    public function key(): string { return 'legacy_autofinx'; }
    public function version(): string { return 'trade-forecast-v1'; }

    public function predict(PredictionRequest $request): PredictionResult
    {
        if (count($request->candles) < 10) {
            return PredictionResult::unavailable($request, $this->key(), $this->version(), 'insufficient_candles');
        }

        $result = $this->forecast->forecast($request->candles, $request->timeframe, $request->horizonSeconds,
            null, null, null, $request->symbol, $request->currentQuote);
        $price = (float) ($result['current_price'] ?? $request->currentQuote ?? 0);
        $target = $result['predicted_expiry_price'] ?? $result['expected_mid_price'] ?? null;
        $returnPct = $price > 0 && $target !== null ? (((float) $target / $price) - 1) * 100 : null;
        $direction = strtoupper((string) ($result['decision_trend'] ?? $result['bias'] ?? 'NEUTRAL'));
        $confidence = isset($result['confidence']) ? (float) $result['confidence'] : null;

        return new PredictionResult(
            provider: $this->key(), providerVersion: $this->version(), generatedAt: now()->toIso8601String(),
            symbol: $request->symbol, marketType: $request->marketType, timeframe: $request->timeframe,
            horizonSeconds: $request->horizonSeconds, available: (bool) ($result['available'] ?? false),
            currentPrice: $price > 0 ? $price : null, predictedExpiryPrice: $target !== null ? (float) $target : null,
            predictedLow: isset($result['predicted_low']) ? (float) $result['predicted_low'] : null,
            predictedHigh: isset($result['predicted_high']) ? (float) $result['predicted_high'] : null,
            predictedReturnPct: $returnPct,
            direction: in_array($direction, ['BUY', 'SELL', 'NEUTRAL'], true) ? $direction : 'NEUTRAL',
            confidence: $confidence, uncertainty: $confidence !== null ? max(0, 100 - $confidence) : null,
            evidence: ['legacy_output' => $result, 'input_basis' => 'closed_candles'],
            factorScores: ['expiry_score' => $result['expiry_score'] ?? null, 'atr_pct' => $result['atr_pct'] ?? null],
            reason: $result['summary'] ?? null,
            dataQuality: (bool) ($result['available'] ?? false) ? 'GOOD' : 'DEGRADED',
        );
    }
}
