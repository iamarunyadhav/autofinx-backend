<?php

namespace App\Domain\MarketData\Data;

final readonly class NormalizedQuote
{
    public function __construct(
        public string $provider,
        public string $instrument,
        public string $providerSymbol,
        public string $marketType,
        public ?float $bid,
        public ?float $ask,
        public ?float $mid,
        public ?float $last,
        public ?float $spread,
        public int $providerEventAtMs,
        public int $receivedAtMs,
        public int $processedAtMs,
        public ?string $sequence = null,
        public string $source = 'websocket',
        public array $metadata = [],
    ) {
    }

    public static function fromLastPrice(string $provider, string $instrument, string $providerSymbol, string $marketType, float $last, int $providerEventAtMs, int $receivedAtMs, ?int $processedAtMs = null, string $source = 'websocket', array $metadata = []): self
    {
        return new self($provider, $instrument, $providerSymbol, $marketType, null, null, $last, $last, null, $providerEventAtMs, $receivedAtMs, $processedAtMs ?? (int) floor(microtime(true) * 1000), null, $source, $metadata);
    }

    public static function fromBidAsk(string $provider, string $instrument, string $providerSymbol, string $marketType, float $bid, float $ask, int $providerEventAtMs, int $receivedAtMs, ?int $processedAtMs = null, string $source = 'websocket', array $metadata = []): self
    {
        $mid = ($bid + $ask) / 2;

        return new self($provider, $instrument, $providerSymbol, $marketType, $bid, $ask, $mid, null, max(0.0, $ask - $bid), $providerEventAtMs, $receivedAtMs, $processedAtMs ?? (int) floor(microtime(true) * 1000), null, $source, $metadata);
    }

    public function executionPriceForSide(string $side): ?float
    {
        return match (strtoupper(trim($side))) {
            'BUY' => $this->ask ?? $this->last ?? $this->mid,
            'SELL' => $this->bid ?? $this->last ?? $this->mid,
            default => $this->last ?? $this->mid,
        };
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'instrument' => $this->instrument,
            'provider_symbol' => $this->providerSymbol,
            'market_type' => $this->marketType,
            'bid' => $this->bid,
            'ask' => $this->ask,
            'mid' => $this->mid,
            'last' => $this->last,
            'spread' => $this->spread,
            'provider_event_at_ms' => $this->providerEventAtMs,
            'received_at_ms' => $this->receivedAtMs,
            'processed_at_ms' => $this->processedAtMs,
            'sequence' => $this->sequence,
            'source' => $this->source,
            'metadata' => $this->metadata,
        ];
    }
}
