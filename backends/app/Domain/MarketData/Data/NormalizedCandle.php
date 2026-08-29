<?php

namespace App\Domain\MarketData\Data;

final readonly class NormalizedCandle
{
    public function __construct(
        public string $provider,
        public string $instrument,
        public string $marketType,
        public string $timeframe,
        public int $openTimeMs,
        public int $closeTimeMs,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public ?float $volume,
        public string $volumeType,
        public bool $isClosed,
        public int $receivedAtMs,
        public string $source = 'websocket',
    ) {
    }
}
