<?php

namespace App\Domain\Prediction\Providers;

use App\Domain\Prediction\Contracts\PredictionProviderInterface;
use App\Domain\Prediction\Data\PredictionRequest;
use App\Domain\Prediction\Data\PredictionResult;

final class PineStrategyPredictionProvider implements PredictionProviderInterface
{
    public function key(): string { return 'pine_strategy'; }
    public function version(): string { return 'not_supplied'; }
    public function predict(PredictionRequest $request): PredictionResult
    {
        return PredictionResult::unavailable($request, $this->key(), $this->version(), 'pine_strategy_not_supplied');
    }
}
