<?php

return [
    'paper_trading' => env('FUTURE_TRADE_PAPER_TRADING', true),
    'enable_testnet_trading' => env('FUTURE_TRADE_ENABLE_TESTNET_TRADING', false),
    'enable_live_trading' => env('FUTURE_TRADE_ENABLE_LIVE_TRADING', false),
    'force_testnet' => env('FUTURE_TRADE_FORCE_TESTNET', true),

    // All futures symbols available in the UI selector
    'available_symbols' => [
        'BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'SOLUSDT', 'XRPUSDT',
        'ADAUSDT', 'AVAXUSDT', 'DOTUSDT', 'LINKUSDT', 'MATICUSDT',
        'DOGEUSDT', 'LTCUSDT', 'UNIUSDT', 'ATOMUSDT', 'NEARUSDT',
        'APTUSDT', 'ARBUSDT', 'OPUSDT', 'SUIUSDT', 'SEIUSDT',
    ],

    'defaults' => [
        // Scan these 6 coins by default — diversified across sectors
        'symbols' => ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'AVAXUSDT'],
        'timeframe' => '5m',
        'paperTrading' => true,
        'liveTrading' => false,
        'forceTestnet' => true,
        'leverage' => 3,
        'marginMode' => 'isolated',
        'tradeAmountUsdt' => 25,
        // Higher confidence threshold — only enter on strong signals
        'minConfidence' => 0.74,
        // TP at 1.2% with 3x leverage = 3.6% on notional — worthwhile
        'takeProfitPercent' => 1.2,
        // Tighter SL: 0.4% stop, keeping R:R at 3:1
        'stopLossPercent' => 0.4,
        'trailingTakeProfitPercent' => 0.30,
        // Tighter rollback — cut losses sooner
        'rollbackLossPercent' => 0.25,
        'maxDailyLossPercent' => 2,
        'maxOpenPositions' => 1,
        // Fewer high-quality trades rather than many low-quality ones
        'maxTradesPerDay' => 10,
        'maxLeverage' => 5,
        'maxSpreadPercent' => 0.12,
        // Longer cooldown prevents chasing failed trades
        'cooldownSeconds' => 45,
        'scanIntervalSeconds' => 3,
        'confidenceMode' => 'semi_dynamic',
        'dynamicLookbackMinutes' => 10,
        'dynamicMinConfidenceFloor' => 0.64,
        'dynamicMinConfidenceCeiling' => 0.88,
        'dynamicReentryBuffer' => 0.015,
        'dynamicSuggestionEnabled' => true,
        'autoExecutePaperSignals' => true,
        'avoidChoppyMarket' => true,
        'strictEntryGuards' => false,
        'emergencyStop' => false,
    ],
];
