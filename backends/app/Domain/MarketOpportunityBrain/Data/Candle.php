<?php

namespace App\Domain\MarketOpportunityBrain\Data;

/**
 * Candle contract (MASTER_SPEC.md Layer D §44): one closed OHLCV bar with explicit provenance
 * timestamps. `observedAt` is when the brain actually saw the bar, which may lag `closeTime`;
 * `isClosed=false` must never be used to label a confirmed bar's features.
 */
final readonly class Candle
{
    public function __construct(
        public string $symbol,
        public string $timeframe,
        public string $openTimeUtc,
        public string $closeTimeUtc,
        public string $observedAtUtc,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public float $volume,
        public bool $isClosed,
        public string $source,
        public ?float $quoteVolume = null,
        public ?float $takerVolume = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'open_time' => $this->openTimeUtc,
            'close_time' => $this->closeTimeUtc,
            'observed_at' => $this->observedAtUtc,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'volume' => $this->volume,
            'is_closed' => $this->isClosed,
            'source' => $this->source,
            'quote_volume' => $this->quoteVolume,
            'taker_volume' => $this->takerVolume,
        ];
    }
}
