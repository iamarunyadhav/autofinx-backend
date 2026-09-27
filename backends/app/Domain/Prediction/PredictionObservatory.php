<?php

namespace App\Domain\Prediction;

use App\Domain\Prediction\Contracts\PredictionProviderInterface;
use App\Domain\Prediction\Data\PredictionRequest;
use Throwable;

final class PredictionObservatory
{
    /** @param array<int, PredictionProviderInterface> $providers */
    public function __construct(private readonly array $providers)
    {
    }

    public function observe(PredictionRequest $request): array
    {
        $results = [];

        foreach ($this->providers as $provider) {
            $key = $provider->key();
            $enabled = (bool) config("prediction_observatory.providers.{$key}.enabled", false);

            if (! $enabled) {
                continue;
            }

            try {
                $result = $provider->predict($request);
                $results[$key] = [
                    'key' => $key,
                    'version' => $provider->version(),
                    'enabled' => true,
                    'influence' => false,
                    'shadow_only' => true,
                    'result' => $result->toArray(),
                ];
            } catch (Throwable $exception) {
                report($exception);
                $results[$key] = [
                    'key' => $key,
                    'version' => $provider->version(),
                    'enabled' => true,
                    'influence' => false,
                    'shadow_only' => true,
                    'result' => [
                        'provider' => $key,
                        'provider_version' => $provider->version(),
                        'available' => false,
                        'symbol' => $request->symbol,
                        'market_type' => $request->marketType,
                        'timeframe' => $request->timeframe,
                        'horizon_seconds' => $request->horizonSeconds,
                        'reason' => 'provider_exception',
                        'data_quality' => 'DEGRADED',
                    ],
                ];
            }
        }

        return [
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'symbol' => $request->symbol,
            'market_type' => $request->marketType,
            'timeframe' => $request->timeframe,
            'horizon_seconds' => $request->horizonSeconds,
            'shadow_only' => true,
            'influence_enabled' => false,
            'providers' => $results,
        ];
    }
}
