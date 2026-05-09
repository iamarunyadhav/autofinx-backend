<?php

return [
    'paper_trading' => env('PAPER_TRADING', true),
    'enable_live_trading' => env('ENABLE_LIVE_TRADING', env('LIVE_TRADING_ENABLED', false)),
    'default_symbol' => env('FAST2X_DEFAULT_SYMBOL', 'BTCUSDT'),
    'max_latency_ms' => (int) env('FAST2X_MAX_LATENCY_MS', 500),
    'polling_interval_ms' => (int) env('FAST2X_POLLING_INTERVAL_MS', 1000),
    'websocket_retry_seconds' => (int) env('FAST2X_WEBSOCKET_RETRY_SECONDS', 30),
    'max_trades_per_day' => (int) env('FAST2X_MAX_TRADES_PER_DAY', 20),
    'max_daily_loss_percent' => (float) env('FAST2X_MAX_DAILY_LOSS_PERCENT', 2),

    // All tradeable symbols available in the UI selector
    'available_symbols' => [
        'BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'SOLUSDT', 'XRPUSDT',
        'ADAUSDT', 'AVAXUSDT', 'DOTUSDT', 'LINKUSDT', 'MATICUSDT',
        'DOGEUSDT', 'LTCUSDT', 'UNIUSDT', 'ATOMUSDT', 'NEARUSDT',
    ],

    'defaults' => [
        'symbol' => env('FAST2X_DEFAULT_SYMBOL', 'BTCUSDT'),
        'tradeMode' => 'SPOT',
        'botMode' => 'FAST2X',
        'paperTrading' => true,
        'tradeAmountType' => 'PERCENTAGE',
        'tradeAmountValue' => 10,
        // Minimum profit must cover round-trip fees (0.2%) + slippage (0.04%) + margin
        'minProfitTargetPercent' => 0.35,
        // Give position breathing room — normal noise is ~0.12–0.18%
        'rollbackLossPercent' => 0.22,
        'manualSellThresholdPercent' => 0.45,
        'manualBuyThresholdPercent' => 0.45,
        'useDynamicThreshold' => true,
        'useIndicators' => true,
        'useStopLoss' => true,
        'useTrailingTakeProfit' => true,
        'stopLossPercent' => 0.38,
        // Tighter trailing locks profit faster once price peaks
        'trailingTakeProfitPercent' => 0.15,
        'maxAllowedSlippagePercent' => 0.15,
        'minimumProfitAfterFeesPercent' => 0.28,
        // 45s cooldown prevents re-entry into falling market after loss
        'cooldownSeconds' => 45,
        // Loss trade cooldown is longer than win trade cooldown
        'cooldownSecondsAfterLoss' => 120,
        'maxTradesPerMinute' => 3,
        'maxTradesPerDay' => 20,
        'maxDailyLossPercent' => 2,
        'maxApiLatencyMs' => 500,
        'emergencyStop' => false,
        'feePercent' => 0.1,
        'estimatedSlippagePercent' => 0.02,
        'useExternalSignals' => true,
        'requireExternalSignalAgreement' => true,
        'externalSignalSource' => 'mt5-mtf-pro',
        'externalSignalTtlSeconds' => 180,
        'externalSignalMinConfidence' => 0.68,
        'externalSignalMinScore' => 7,
        'externalSignalMinRR' => 2.0,
        'externalSignalRequireHtf' => true,
        'externalSignalBlockHighNews' => true,
        'externalSignalForecastWeight' => 0.10,
        'fusedEntryMinConfidence' => 0.74,
    ],
];
