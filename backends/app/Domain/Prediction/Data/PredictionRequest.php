<?php

namespace App\Domain\Prediction\Data;

final class PredictionRequest
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $marketType,
        public readonly string $timeframe,
        public readonly int $horizonSeconds,
        public readonly ?float $currentQuote,
        public readonly array $candles,
        public readonly array $marketContext = [],
    ) {
    }
}
