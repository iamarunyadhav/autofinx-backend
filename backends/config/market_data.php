<?php

return [
    'default_provider' => env('MARKET_DATA_DEFAULT_PROVIDER', 'binance'),
    'providers' => [
        'binance' => [
            'market_types' => ['CRYPTO'],
            'adapter' => App\Domain\MarketData\Providers\BinanceMarketDataProvider::class,
            'capabilities' => ['quotes' => true, 'trades' => true, 'candles' => true, 'order_book' => true, 'tick_volume' => false, 'real_volume' => true, 'market_sessions' => false],
        ],
        'mock_forex' => [
            'market_types' => ['FOREX', 'METAL'],
            'adapter' => null,
            'capabilities' => ['quotes' => true, 'trades' => false, 'candles' => true, 'order_book' => false, 'tick_volume' => true, 'real_volume' => false, 'market_sessions' => true],
        ],
    ],
    'instruments' => [
        'BTCUSDT' => ['market_type' => 'CRYPTO', 'base_asset' => 'BTC', 'quote_asset' => 'USDT', 'provider_symbols' => ['binance' => 'BTCUSDT']],
        'ETHUSDT' => ['market_type' => 'CRYPTO', 'base_asset' => 'ETH', 'quote_asset' => 'USDT', 'provider_symbols' => ['binance' => 'ETHUSDT']],
        'SOLUSDT' => ['market_type' => 'CRYPTO', 'base_asset' => 'SOL', 'quote_asset' => 'USDT', 'provider_symbols' => ['binance' => 'SOLUSDT']],
        'EURUSD' => ['market_type' => 'FOREX', 'base_asset' => 'EUR', 'quote_asset' => 'USD', 'provider_symbols' => []],
        'GBPUSD' => ['market_type' => 'FOREX', 'base_asset' => 'GBP', 'quote_asset' => 'USD', 'provider_symbols' => []],
        'XAUUSD' => ['market_type' => 'METAL', 'base_asset' => 'XAU', 'quote_asset' => 'USD', 'provider_symbols' => ['mock_forex' => 'GOLD']],
        'XAGUSD' => ['market_type' => 'METAL', 'base_asset' => 'XAG', 'quote_asset' => 'USD', 'provider_symbols' => ['mock_forex' => 'SILVER']],
    ],
    'profiles' => [
        'CRYPTO' => ['trading_schedule' => 'continuous', 'has_exchange_volume' => true, 'has_order_book' => true, 'uses_bid_ask' => true],
        'FOREX' => ['trading_schedule' => 'session_based', 'uses_bid_ask' => true, 'has_central_exchange_volume' => false, 'supports_tick_volume' => true],
        'METAL' => ['trading_schedule' => 'provider_defined', 'uses_bid_ask' => true, 'supports_tick_volume' => true],
    ],
    'freshness' => [
        'default' => [
            'QUOTE' => ['expected_interval_ms' => 1000, 'degraded_after_ms' => 5000, 'stale_after_ms' => 15000, 'ttl_seconds' => 120],
            'TRADE' => ['expected_interval_ms' => 1000, 'degraded_after_ms' => 5000, 'stale_after_ms' => 15000, 'ttl_seconds' => 120],
            'CANDLE' => ['expected_interval_ms' => 60000, 'degraded_after_ms' => 90000, 'stale_after_ms' => 180000, 'ttl_seconds' => 600],
        ],
        'CRYPTO' => [
            'QUOTE' => ['expected_interval_ms' => 1000, 'degraded_after_ms' => 5000, 'stale_after_ms' => 15000, 'ttl_seconds' => 120],
            'TRADE' => ['expected_interval_ms' => 1000, 'degraded_after_ms' => 5000, 'stale_after_ms' => 15000, 'ttl_seconds' => 120],
            'CANDLE' => ['expected_interval_ms' => 60000, 'degraded_after_ms' => 90000, 'stale_after_ms' => 180000, 'ttl_seconds' => 600],
        ],
        'FOREX' => ['QUOTE' => ['expected_interval_ms' => 1000, 'degraded_after_ms' => 10000, 'stale_after_ms' => 30000, 'ttl_seconds' => 120]],
        'METAL' => ['QUOTE' => ['expected_interval_ms' => 1000, 'degraded_after_ms' => 10000, 'stale_after_ms' => 30000, 'ttl_seconds' => 120]],
    ],
    'recovery' => [
        'lock_seconds' => 30,
        'healthy_events_required' => (int) env('MARKET_DATA_RECOVERY_HEALTHY_EVENTS', 3),
        'backoff_ms' => [500, 1000, 2000, 3000, 5000],
    ],
];
