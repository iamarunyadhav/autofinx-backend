<?php

namespace App\Domain\Ml\Shadow;

use Illuminate\Support\Facades\Http;
use Throwable;

/** HTTP client for the python predictor's shadow endpoint. Never throws. */
class PythonShadowClient
{
    /**
     * @param  array<string, float>  $features
     * @return array<string, mixed>
     */
    public function predict(string $symbol, int $horizon, array $features, string $expectedModelVersion): array
    {
        try {
            $response = Http::timeout((float) config('ml.predictor.timeout_seconds', 3))
                ->acceptJson()
                ->post(rtrim((string) config('ml.predictor.url'), '/').'/v2/shadow/predict', [
                    'symbol' => $symbol,
                    'horizon_minutes' => $horizon,
                    'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION,
                    'features' => $features,
                    'expected_model_version' => $expectedModelVersion,
                ]);
            if ($response->failed()) {
                return ['status' => 'unavailable', 'reason' => 'predictor_http_'.$response->status()];
            }

            return (array) ($response->json('prediction') ?? ['status' => 'unavailable', 'reason' => 'predictor_empty_response']);
        } catch (Throwable $exception) {
            return ['status' => 'unavailable', 'reason' => 'predictor_unreachable'];
        }
    }
}
