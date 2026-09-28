<?php

namespace App\Domain\Prediction\Providers;

use App\Domain\Prediction\Contracts\PredictionProviderInterface;
use App\Domain\Prediction\Data\PredictionRequest;
use App\Domain\Prediction\Data\PredictionResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ExternalMlPredictionProvider implements PredictionProviderInterface
{
    public function key(): string { return 'external_ml'; }
    public function version(): string { return 'external-service-v1'; }
    public function predict(PredictionRequest $request): PredictionResult
    {
        if (count($request->candles) < 8) return PredictionResult::unavailable($request, $this->key(), $this->version(), 'insufficient_candles');
        try {
            $response = Http::timeout((int) config('prediction_observatory.providers.external_ml.timeout_seconds', 2))
                ->acceptJson()->post(rtrim((string) config('prediction_observatory.providers.external_ml.url'), '/').'/v1/predict', [
                    'symbol' => $request->symbol, 'horizon_seconds' => $request->horizonSeconds,
                    'market_timestamp' => (string) ($request->candles[array_key_last($request->candles)]['timestamp'] ?? ''),
                    'features' => $this->features($request->candles),
                ]);
            if (!$response->successful()) return PredictionResult::unavailable($request, $this->key(), $this->version(), 'external_ml_http_error');
            $prediction = (array) $response->json('prediction', []);
            if (($prediction['status'] ?? '') !== 'ready') return PredictionResult::unavailable($request, $this->key(), (string) ($prediction['model_version'] ?? $this->version), (string) ($prediction['reason'] ?? 'external_ml_unavailable'));
            return new PredictionResult(provider: $this->key(), providerVersion: (string) ($prediction['model_version'] ?? $this->version), generatedAt: now()->toIso8601String(), symbol: $request->symbol, marketType: $request->marketType, timeframe: $request->timeframe, horizonSeconds: $request->horizonSeconds, available: true, currentPrice: $request->currentQuote, predictedReturnPct: isset($prediction['predicted_return_pct']) ? (float) $prediction['predicted_return_pct'] : null, direction: (string) ($prediction['direction'] ?? 'WAIT'), confidence: isset($prediction['confidence']) ? (float) $prediction['confidence'] : null, evidence: ['source' => 'external_predictive_engine'], dataQuality: 'GOOD');
        } catch (Throwable) {
            return PredictionResult::unavailable($request, $this->key(), $this->version(), 'external_ml_timeout_or_connection_error');
        }
    }

    private function features(array $candles): array
    {
        $last = $candles[array_key_last($candles)]; $previous = $candles[count($candles) - 2];
        $closes = array_map(static fn (array $candle): float => (float) $candle['close'], array_slice($candles, -20));
        $volumes = array_map(static fn (array $candle): float => (float) ($candle['volume'] ?? 0), array_slice($candles, -20));
        $averageVolume = $volumes ? array_sum($volumes) / count($volumes) : 0;
        return ['return_1' => ((float) $last['close'] / (float) $previous['close']) - 1, 'distance_from_low_20' => ((float) $last['close'] / min($closes)) - 1, 'distance_from_high_20' => (max($closes) / (float) $last['close']) - 1, 'volume_ratio_20' => $averageVolume > 0 ? ((float) ($last['volume'] ?? 0) / $averageVolume) : 0];
    }
}
