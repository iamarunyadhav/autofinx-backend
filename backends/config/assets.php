<?php

return [
    'classes' => [
        'CRYPTO',
        'METALS',
        'FOREX',
    ],

    'quote_currencies' => [
        'USD',
        'LKR',
        'USDT',
    ],

    'crypto' => [
        'default_provider' => 'binance',
        'symbols' => [
            'BTCUSDT' => [
                'asset_class' => 'CRYPTO',
                'asset_code' => 'BTCUSDT',
                'base' => 'BTC',
                'quote' => 'USDT',
                'min_qty' => 0.0001,
                'qty_precision' => 6,
                'price_precision' => 2,
                'fee_pct' => 0.1,
            ],
            'ETHUSDT' => [
                'asset_class' => 'CRYPTO',
                'asset_code' => 'ETHUSDT',
                'base' => 'ETH',
                'quote' => 'USDT',
                'min_qty' => 0.001,
                'qty_precision' => 6,
                'price_precision' => 2,
                'fee_pct' => 0.1,
            ],
        ],
    ],

    'metals' => [
        'default_provider' => 'metal_broker',
        'symbols' => [
            'GOLD' => [
                'asset_class' => 'METALS',
                'metal_type' => 'GOLD',
                'asset_code' => 'XAUUSD',
                'base' => 'XAU',
                'quote' => 'USD',
                'min_qty' => 0.01,
                'qty_precision' => 4,
                'price_precision' => 2,
                'fee_pct' => 0.15,
                'max_spread_pct_default' => 0.35,
            ],
            'SILVER' => [
                'asset_class' => 'METALS',
                'metal_type' => 'SILVER',
                'asset_code' => 'XAGUSD',
                'base' => 'XAG',
                'quote' => 'USD',
                'min_qty' => 0.1,
                'qty_precision' => 4,
                'price_precision' => 3,
                'fee_pct' => 0.18,
                'max_spread_pct_default' => 0.5,
            ],
            'BRONZE' => [
                'asset_class' => 'METALS',
                'metal_type' => 'BRONZE',
                'asset_code' => 'XCUUSD',
                'base' => 'XCU',
                'quote' => 'USD',
                'min_qty' => 0.1,
                'qty_precision' => 4,
                'price_precision' => 3,
                'fee_pct' => 0.2,
                'max_spread_pct_default' => 0.7,
            ],
        ],
        'market_hours' => [
            'timezone' => 'UTC',
            'open' => '22:00',
            'close' => '21:00',
            'closed_weekdays' => [6], // Saturday (0=Sun..6=Sat)
        ],
    ],

    // Currency pairs served by the same generic broker as 'metals' (see
    // config/live_providers.php: metal_broker). Placeholder symbols/precision
    // until a real forex broker is chosen — see docs/architecture/multi_broker_crypto_forex.md.
    'forex' => [
        'default_provider' => 'metal_broker',
        'symbols' => [
            'EURUSD' => [
                'asset_class' => 'FOREX',
                'asset_code' => 'EURUSD',
                'base' => 'EUR',
                'quote' => 'USD',
                'min_qty' => 1000,
                'qty_precision' => 0,
                'price_precision' => 5,
                'fee_pct' => 0.02,
                'max_spread_pct_default' => 0.05,
            ],
            'GBPUSD' => [
                'asset_class' => 'FOREX',
                'asset_code' => 'GBPUSD',
                'base' => 'GBP',
                'quote' => 'USD',
                'min_qty' => 1000,
                'qty_precision' => 0,
                'price_precision' => 5,
                'fee_pct' => 0.02,
                'max_spread_pct_default' => 0.05,
            ],
            'USDJPY' => [
                'asset_class' => 'FOREX',
                'asset_code' => 'USDJPY',
                'base' => 'USD',
                'quote' => 'JPY',
                'min_qty' => 1000,
                'qty_precision' => 0,
                'price_precision' => 3,
                'fee_pct' => 0.02,
                'max_spread_pct_default' => 0.05,
            ],
        ],
        'market_hours' => [
            'timezone' => 'UTC',
            'open' => '22:00',
            'close' => '22:00',
            'closed_weekdays' => [6], // Saturday
        ],
    ],
];

