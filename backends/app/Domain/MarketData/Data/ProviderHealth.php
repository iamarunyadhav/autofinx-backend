<?php

namespace App\Domain\MarketData\Data;

use App\Domain\MarketData\Enums\FeedState;

final readonly class ProviderHealth
{
    public function __construct(public string $provider, public FeedState $state, public array $capabilities = [], public ?string $reason = null)
    {
    }

    public function toArray(): array
    {
        return ['provider' => $this->provider, 'state' => $this->state->value, 'capabilities' => $this->capabilities, 'reason' => $this->reason];
    }
}
