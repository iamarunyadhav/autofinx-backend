<?php

namespace App\Domain\Prediction\Contracts;

use App\Domain\Prediction\Data\PredictionRequest;
use App\Domain\Prediction\Data\PredictionResult;

interface PredictionProviderInterface
{
    public function key(): string;

    public function version(): string;

    public function predict(PredictionRequest $request): PredictionResult;
}
