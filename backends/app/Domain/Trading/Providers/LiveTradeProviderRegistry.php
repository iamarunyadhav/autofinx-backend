<?php

namespace App\Domain\Trading\Providers;

use RuntimeException;

class LiveTradeProviderRegistry
{
    public function __construct(
        private readonly BinanceTimedFuturesProvider $binance,
    ) {
    }

    public function for(string $provider): LiveTradeProviderInterface
    {
        $key = strtolower(trim($provider));
        $providerClass = config("live_providers.{$key}.provider_class");

        if ($key === 'binance') {
            return $this->binance;
        }

        if ($providerClass !== null && is_a($providerClass, LiveTradeProviderInterface::class, true)) {
            return app($providerClass);
        }

        throw new RuntimeException("Unsupported live provider [{$provider}].");
    }

    public function catalog(): array
    {
        $catalog = [];

        foreach ((array) config('live_providers', []) as $key => $entry) {
            $catalog[$key] = $key === 'binance'
                ? array_merge($entry, $this->binance->capabilities())
                : $entry;
        }

        return $catalog;
    }
}
