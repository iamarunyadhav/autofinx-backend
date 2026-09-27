<?php

namespace App\Domain\Prediction\Data;

final class PredictionResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerVersion,
        public readonly string $generatedAt,
        public readonly string $symbol,
        public readonly string $marketType,
        public readonly string $timeframe,
        public readonly int $horizonSeconds,
        public readonly bool $available,
        public readonly ?float $currentPrice = null,
        public readonly ?float $predictedExpiryPrice = null,
        public readonly ?float $predictedLow = null,
        public readonly ?float $predictedHigh = null,
        public readonly ?float $predictedReturnPct = null,
        public readonly ?string $direction = null,
        public readonly ?float $buyProbability = null,
        public readonly ?float $sellProbability = null,
        public readonly ?float $neutralProbability = null,
        public readonly ?float $confidence = null,
        public readonly ?float $uncertainty = null,
        public readonly array $evidence = [],
        public readonly array $factorScores = [],
        public readonly ?string $reason = null,
        public readonly string $dataQuality = 'UNKNOWN',
    ) {
    }

    public static function unavailable(PredictionRequest $request, string $provider, string $version, string $reason): self
    {
        return new self(
            provider: $provider, providerVersion: $version, generatedAt: now()->toIso8601String(),
            symbol: $request->symbol, marketType: $request->marketType, timeframe: $request->timeframe,
            horizonSeconds: $request->horizonSeconds, available: false, reason: $reason, dataQuality: 'DEGRADED',
        );
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider, 'provider_version' => $this->providerVersion,
            'generated_at' => $this->generatedAt, 'symbol' => $this->symbol,
            'market_type' => $this->marketType, 'timeframe' => $this->timeframe,
            'horizon_seconds' => $this->horizonSeconds, 'available' => $this->available,
            'current_price' => $this->currentPrice, 'predicted_expiry_price' => $this->predictedExpiryPrice,
            'predicted_low' => $this->predictedLow, 'predicted_high' => $this->predictedHigh,
            'predicted_return_pct' => $this->predictedReturnPct, 'direction' => $this->direction,
            'buy_probability' => $this->buyProbability, 'sell_probability' => $this->sellProbability,
            'neutral_probability' => $this->neutralProbability, 'confidence' => $this->confidence,
            'uncertainty' => $this->uncertainty, 'evidence' => $this->evidence,
            'factor_scores' => $this->factorScores, 'reason' => $this->reason,
            'data_quality' => $this->dataQuality,
        ];
    }
}
