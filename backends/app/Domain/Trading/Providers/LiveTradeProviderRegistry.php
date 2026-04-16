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
        return match (strtolower(trim($provider))) {
            'binance' => $this->binance,
            default => throw new RuntimeException("Unsupported live provider [{$provider}]."),
        };
    }

    public function catalog(): array
    {
        return [
            'binance' => $this->binance->capabilities(),
            'coinbase' => [
                'key' => 'coinbase',
                'label' => 'Coinbase',
                'live_supported' => false,
                'default_market_type' => 'spot',
                'supports_short' => false,
                'requires_integration' => true,
                'symbols' => [],
                'timeframes' => ['1m', '5m', '15m'],
                'durations' => [60, 300, 900],
            ],
            'bybit' => [
                'key' => 'bybit',
                'label' => 'Bybit',
                'live_supported' => false,
                'default_market_type' => 'futures',
                'supports_short' => true,
                'requires_integration' => true,
                'symbols' => [],
                'timeframes' => ['1m', '5m', '15m'],
                'durations' => [60, 300, 900],
            ],
            'kucoin' => [
                'key' => 'kucoin',
                'label' => 'KuCoin',
                'live_supported' => false,
                'default_market_type' => 'spot',
                'supports_short' => false,
                'requires_integration' => true,
                'symbols' => [],
                'timeframes' => ['1m', '5m', '15m'],
                'durations' => [60, 300, 900],
            ],
        ];
    }
}
