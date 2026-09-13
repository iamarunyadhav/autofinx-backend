<?php

// Single source of truth for which trading providers/venues exist and what
// they support. LiveTradeProviderRegistry::catalog() reads this map instead
// of hardcoding provider entries inline — the 'binance' row's live-capability
// fields are still probed dynamically via BinanceTimedFuturesProvider::capabilities()
// so they can't drift from the real client, but everything else (labels,
// symbols, which providers exist at all) lives here.
//
// 'provider_class' here is a LiveTradeProviderInterface implementation (the
// narrow "timed trade" execution contract used by Quick Trade/Robot Trade's
// live path) — NOT the same as TradingVenueClientInterface/MetalBrokerClient,
// which TradingVenueClientFactory resolves separately. Leave 'provider_class'
// null until a provider has a real LiveTradeProviderInterface implementation;
// LiveTradeProviderRegistry::for() correctly throws until then.
//
// See docs/architecture/multi_broker_crypto_forex.md for the full design.

return [
    'binance' => [
        'key' => 'binance',
        'label' => 'Binance',
        'asset_class' => 'CRYPTO',
        'provider_class' => \App\Domain\Trading\Providers\BinanceTimedFuturesProvider::class,
    ],

    'metal_broker' => [
        'key' => 'metal_broker',
        'label' => 'Forex & Metals Broker',
        'asset_class' => 'FOREX',
        // No LiveTradeProviderInterface implementation yet — TradingVenueClientFactory
        // already resolves this provider_key to MetalBrokerClient for the
        // connection-test / bot-execution paths, which is a separate interface.
        'provider_class' => null,
        'live_supported' => false,
        'default_market_type' => 'spot',
        'supports_short' => true,
        'requires_integration' => true,
        'symbols' => [
            ['symbol' => 'XAUUSD', 'display' => 'Gold / USD', 'asset_class' => 'METALS', 'payout_rate' => 0],
            ['symbol' => 'XAGUSD', 'display' => 'Silver / USD', 'asset_class' => 'METALS', 'payout_rate' => 0],
            ['symbol' => 'XCUUSD', 'display' => 'Copper / USD', 'asset_class' => 'METALS', 'payout_rate' => 0],
            ['symbol' => 'EURUSD', 'display' => 'EUR / USD', 'asset_class' => 'FOREX', 'payout_rate' => 0],
            ['symbol' => 'GBPUSD', 'display' => 'GBP / USD', 'asset_class' => 'FOREX', 'payout_rate' => 0],
            ['symbol' => 'USDJPY', 'display' => 'USD / JPY', 'asset_class' => 'FOREX', 'payout_rate' => 0],
        ],
        'timeframes' => ['1m', '5m', '15m'],
        'durations' => [60, 300, 900],
        // sandbox until a real broker is chosen and services.metal_broker.* is
        // populated — see MetalBrokerClient::sandboxQuote()
        'mode_note' => 'sandbox — no real broker connected yet',
    ],

    'oanda' => [
        'key' => 'oanda',
        'label' => 'OANDA',
        'asset_class' => 'FOREX',
        // No LiveTradeProviderInterface implementation yet (same status as
        // metal_broker) -- TradingVenueClientFactory resolves this
        // provider_key to OandaClient for connection-test/bot-execution
        // paths, a separate interface. See OandaClient's own docblock: real,
        // documented v20 API shape, built and tested in sandbox mode only
        // (no live account available when this was written).
        'provider_class' => null,
        'live_supported' => false,
        'default_market_type' => 'spot',
        'supports_short' => true,
        'requires_integration' => true,
        'symbols' => [
            ['symbol' => 'EURUSD', 'display' => 'EUR / USD', 'asset_class' => 'FOREX', 'payout_rate' => 0],
            ['symbol' => 'GBPUSD', 'display' => 'GBP / USD', 'asset_class' => 'FOREX', 'payout_rate' => 0],
            ['symbol' => 'USDJPY', 'display' => 'USD / JPY', 'asset_class' => 'FOREX', 'payout_rate' => 0],
            ['symbol' => 'XAUUSD', 'display' => 'Gold / USD', 'asset_class' => 'METALS', 'payout_rate' => 0],
            ['symbol' => 'XAGUSD', 'display' => 'Silver / USD', 'asset_class' => 'METALS', 'payout_rate' => 0],
        ],
        'timeframes' => ['1m', '5m', '15m', '1h'],
        'durations' => [60, 300, 900],
        'mode_note' => 'sandbox — real API shape, no live account connected yet',
    ],

    'pepperstone' => [
        'key' => 'pepperstone',
        'label' => 'Pepperstone',
        'asset_class' => 'FOREX',
        // Same status as 'oanda' above: no LiveTradeProviderInterface
        // implementation yet -- TradingVenueClientFactory resolves this
        // provider_key to PepperstoneBrokerAdapter for connection-test/bot-
        // execution paths. Sandbox mode only until a real account exists
        // (Phase 0) -- see PepperstoneBrokerAdapter's own docblock.
        'provider_class' => null,
        'live_supported' => false,
        'default_market_type' => 'spot',
        'supports_short' => true,
        'requires_integration' => true,
        'symbols' => [
            ['symbol' => 'XAUUSD', 'display' => 'Gold / USD', 'asset_class' => 'METALS', 'payout_rate' => 0],
            ['symbol' => 'XAGUSD', 'display' => 'Silver / USD', 'asset_class' => 'METALS', 'payout_rate' => 0],
            ['symbol' => 'XPTUSD', 'display' => 'Platinum / USD', 'asset_class' => 'METALS', 'payout_rate' => 0],
            ['symbol' => 'WTICOUSD', 'display' => 'Crude Oil', 'asset_class' => 'ENERGY', 'payout_rate' => 0],
        ],
        'timeframes' => ['5m', '15m', '30m', '1h', '4h', '1d'],
        'durations' => [300, 900, 1800],
        'mode_note' => 'sandbox — real API shape not yet confirmed, no live account connected yet',
    ],

    'coinbase' => [
        'key' => 'coinbase',
        'label' => 'Coinbase',
        'asset_class' => 'CRYPTO',
        'provider_class' => null,
        'live_supported' => false,
        'default_market_type' => 'spot',
        'supports_short' => false,
        'requires_integration' => true,
        'requires_passphrase' => true,
        'symbols' => [],
        'timeframes' => ['1m', '5m', '15m'],
        'durations' => [60, 300, 900],
    ],

    'kraken' => [
        'key' => 'kraken',
        'label' => 'Kraken',
        'asset_class' => 'CRYPTO',
        'provider_class' => null,
        'live_supported' => false,
        'default_market_type' => 'spot',
        'supports_short' => false,
        'requires_integration' => true,
        'symbols' => [],
        'timeframes' => ['1m', '5m', '15m'],
        'durations' => [60, 300, 900],
    ],

    'okx' => [
        'key' => 'okx',
        'label' => 'OKX',
        'asset_class' => 'CRYPTO',
        'provider_class' => null,
        'live_supported' => false,
        'default_market_type' => 'spot',
        'supports_short' => false,
        'requires_integration' => true,
        'requires_passphrase' => true,
        'symbols' => [],
        'timeframes' => ['1m', '5m', '15m'],
        'durations' => [60, 300, 900],
    ],

    'bybit' => [
        'key' => 'bybit',
        'label' => 'Bybit',
        'asset_class' => 'CRYPTO',
        'provider_class' => null,
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
        'asset_class' => 'CRYPTO',
        'provider_class' => null,
        'live_supported' => false,
        'default_market_type' => 'spot',
        'supports_short' => false,
        'requires_integration' => true,
        'symbols' => [],
        'timeframes' => ['1m', '5m', '15m'],
        'durations' => [60, 300, 900],
    ],
];
