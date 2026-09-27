<?php

namespace App\Domain\Prediction\Providers;

use App\Domain\Prediction\Contracts\PredictionProviderInterface;
use App\Domain\Prediction\Data\PredictionRequest;
use App\Domain\Prediction\Data\PredictionResult;

final class ExternalMlPredictionProvider implements PredictionProviderInterface
{
    public function key(): string { return 'external_ml'; }
    public function version(): string { return 'unconfigured'; }
    public function predict(PredictionRequest $request): PredictionResult
    {
        return PredictionResult::unavailable($request, $this->key(), $this->version(), 'external_ml_service_not_configured');
    }
}
