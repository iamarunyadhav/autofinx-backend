<?php

return [
    'default_signal_profile' => env('FUTURE_TRADE_DEFAULT_SIGNAL_PROFILE', 'autofinx_prime_v6_1_7_crypto'),
    'paper_trading' => env('FUTURE_TRADE_PAPER_TRADING', true),
    'enable_testnet_trading' => env('FUTURE_TRADE_ENABLE_TESTNET_TRADING', false),
    'enable_live_trading' => env('FUTURE_TRADE_ENABLE_LIVE_TRADING', false),
    'force_testnet' => env('FUTURE_TRADE_FORCE_TESTNET', true),
    'shared_hosting_mode' => env('AUTOFINX_SHARED_HOSTING_MODE', false),
    'schedule_runtime_commands' => env('FUTURE_TRADE_SCHEDULE_RUNTIME_COMMANDS', false),
    'schedule' => [
        'runtime_commands' => filter_var(env('FUTURE_TRADE_SCHEDULE_RUNTIME_COMMANDS', false), FILTER_VALIDATE_BOOL),
        'reconcile' => filter_var(env('FUTURE_TRADE_SCHEDULE_RECONCILE', true), FILTER_VALIDATE_BOOL),
        'diagnostics' => filter_var(env('FUTURE_TRADE_SCHEDULE_DIAGNOSTICS', false), FILTER_VALIDATE_BOOL),
        'audit_missed_signals' => filter_var(env('FUTURE_TRADE_SCHEDULE_AUDIT_MISSED_SIGNALS', false), FILTER_VALIDATE_BOOL),
    ],
    'php_binary' => env('FUTURE_TRADE_PHP_BINARY'),
    'pine_webhook_token' => env('FUTURE_TRADE_PINE_WEBHOOK_TOKEN'),
    'prime_mirror_execution_enabled' => env('FUTURE_TRADE_PRIME_MIRROR_EXECUTION_ENABLED', false),
    'prime_mirror_live_enabled' => env('FUTURE_TRADE_PRIME_MIRROR_LIVE_ENABLED', false),
    'pine_webhook_live_enabled' => env('FUTURE_TRADE_PINE_WEBHOOK_LIVE_ENABLED', true),
    'pine_webhook_max_signal_latency_ms' => max(1000, (int) env('FUTURE_TRADE_PINE_WEBHOOK_MAX_SIGNAL_LATENCY_MS', 4000)),
    'live_feed_max_age_seconds' => max(1, (int) env('FUTURE_TRADE_LIVE_FEED_MAX_AGE_SECONDS', 3)),
    'live' => [
        'pine_webhook_enabled' => filter_var(env('FUTURE_TRADE_PINE_WEBHOOK_LIVE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'prime_mirror_enabled' => filter_var(env('FUTURE_TRADE_PRIME_MIRROR_LIVE_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],
    'binance_rest' => [
        'circuit_breaker' => filter_var(env('BINANCE_REST_CIRCUIT_BREAKER', true), FILTER_VALIDATE_BOOL),
        'min_interval_ms' => max(0, (int) env('BINANCE_REST_MIN_INTERVAL_MS', 500)),
        'execution_min_interval_ms' => max(0, (int) env('BINANCE_REST_EXECUTION_MIN_INTERVAL_MS', 150)),
        'ban_cooldown_seconds' => max(60, (int) env('BINANCE_REST_BAN_COOLDOWN_SECONDS', 3600)),
        'recv_window_ms' => max(5000, min(60000, (int) env('BINANCE_FUTURES_RECV_WINDOW', 15000))),
    ],
    'order_router' => [
        'transport' => env('FUTURE_TRADE_ORDER_TRANSPORT', 'rest'),
        'websocket_enabled' => filter_var(env('FUTURE_TRADE_ORDER_WEBSOCKET_ENABLED', false), FILTER_VALIDATE_BOOL),
        'websocket_timeout_seconds' => max(3, min(30, (int) env('FUTURE_TRADE_ORDER_WEBSOCKET_TIMEOUT_SECONDS', 8))),
    ],
    'live_max_open_positions' => max(1, min(5, (int) env('FUTURE_TRADE_LIVE_MAX_OPEN_POSITIONS', 1))),
    'webhook_fallback_enabled' => env('FUTURE_TRADE_WEBHOOK_FALLBACK_ENABLED', false),
    'webhook_risk_overrides' => env('FUTURE_TRADE_WEBHOOK_RISK_OVERRIDES', false),
    'atr_dynamic_risk_targets' => env('FUTURE_TRADE_ATR_DYNAMIC_RISK_TARGETS', false),
    'confirmed_candle_only' => env('FUTURE_TRADE_CONFIRMED_CANDLE_ONLY', true),
    'robot' => [
        'allow_reverse' => filter_var(env('ROBOT_ALLOW_REVERSE', true), FILTER_VALIDATE_BOOL),
        'reverse_min_confidence' => (float) env('ROBOT_REVERSE_MIN_CONFIDENCE', 0.70),
        'total_loss_limit_usdt' => (float) env('ROBOT_TOTAL_LOSS_LIMIT_USDT', 5),
        'daily_loss_limit_usdt' => (float) env('ROBOT_DAILY_LOSS_LIMIT_USDT', 5),
        'per_trade_max_loss_usdt' => (float) env('ROBOT_PER_TRADE_MAX_LOSS_USDT', 1.5),
        'stop_after_total_loss' => filter_var(env('ROBOT_STOP_AFTER_TOTAL_LOSS', true), FILTER_VALIDATE_BOOL),
        'take_profit_percent' => (float) env('ROBOT_TAKE_PROFIT_PERCENT', 0.5),
        'stop_loss_percent' => (float) env('ROBOT_STOP_LOSS_PERCENT', 0.25),
        'trailing_profit_percent' => (float) env('ROBOT_TRAILING_PROFIT_PERCENT', 0.18),
        'min_profit_to_close_usdt' => (float) env('ROBOT_MIN_PROFIT_TO_CLOSE_USDT', 0.20),
    ],

    // Permissive symbol mode (default true): when on, any incoming Pine webhook
    // symbol that matches the Binance-futures pattern `<TICKER>USDT|USDC` is
    // accepted even if it is not in `available_symbols`. Set to false to
    // require strict registry membership.
    'permissive_symbol_mode' => env('FUTURE_TRADE_PERMISSIVE_SYMBOL_MODE', true),

    // Curated Binance USDT-M perpetuals registry — the "global allowlist" the
    // UI selector reads from. Pine webhooks for symbols not in this list will
    // still be accepted via the permissive regex above unless the operator
    // disables permissive_symbol_mode or sets restrictToWorkspaceSymbols=true.
    'available_symbols' => [
        // Top 25 majors
        'BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'SOLUSDT', 'XRPUSDT',
        'ADAUSDT', 'AVAXUSDT', 'DOTUSDT', 'LINKUSDT', 'MATICUSDT',
        'DOGEUSDT', 'LTCUSDT', 'UNIUSDT', 'ATOMUSDT', 'NEARUSDT',
        'APTUSDT', 'ARBUSDT', 'OPUSDT', 'SUIUSDT', 'SEIUSDT',
        'TRXUSDT', 'TONUSDT', 'BCHUSDT', 'ETCUSDT', 'FILUSDT',
        // Next 25 actively traded perps
        'AAVEUSDT', 'INJUSDT', 'TIAUSDT', 'PEPEUSDT', '1000PEPEUSDT',
        'SHIBUSDT', '1000SHIBUSDT', 'WIFUSDT', 'BONKUSDT', '1000BONKUSDT',
        'FLOKIUSDT', '1000FLOKIUSDT', 'ORDIUSDT', 'JUPUSDT', 'WLDUSDT',
        'RNDRUSDT', 'FETUSDT', 'ARKMUSDT', 'TAOUSDT', 'STRKUSDT',
        'PYTHUSDT', 'JTOUSDT', 'IMXUSDT', 'SANDUSDT', 'MANAUSDT',
    ],

    'presets' => [
        'balanced_live_test' => [
            'minConfidence' => 0.52,
            'confidenceMode' => 'fixed',
            'dynamicMinConfidenceFloor' => 0.50,
            'dynamicMinConfidenceCeiling' => 0.62,
            'strictEntryGuards' => false,
            'avoidChoppyMarket' => true,
            'weakSetupAction' => 'reduce_size',
            'directionalMinScore5m' => 5,
            'directionalMinVoteGap5m' => 1,
            'symbolPerfAdjustCap' => 0.04,
            'recentAccuracyLookbackTrades' => 20,
            'cooldownSeconds' => 60,
            'tradeAmountUsdt' => 25,
        ],
    ],

    'defaults' => [
        // Scan these 6 coins by default — diversified across sectors
        'signalProfile' => env('FUTURE_TRADE_SIGNAL_PROFILE', env('FUTURE_TRADE_DEFAULT_SIGNAL_PROFILE', 'autofinx_prime_v6_1_7_crypto')),
        'signalProfileName' => 'AutoFinX Prime v6.1.7 Crypto Stable Default',
        'marketProfile' => 'Crypto',
        // Sources:
        // webhook          => TradingView Pine is the only executor.
        // webhook_fallback => TradingView first; Binance scanner executes only when webhook is stale.
        // mirror           => Existing Binance-backed Prime mirror scanner.
        // binance          => Native backend Binance brain: Binance market data is the source of truth.
        'signalSource' => env('FUTURE_TRADE_SIGNAL_SOURCE', 'webhook'),
        'binanceBrainVersion' => 'v11_native_beta',
        'pineWebhookFallbackTimeoutSeconds' => (int) env('FUTURE_TRADE_PINE_WEBHOOK_FALLBACK_TIMEOUT_SECONDS', 1200),
        'liveFeedMaxAgeSeconds' => max(1, (int) env('FUTURE_TRADE_LIVE_FEED_MAX_AGE_SECONDS', 3)),
        'webhookMaxSignalLatencyMs' => max(1000, (int) env('FUTURE_TRADE_PINE_WEBHOOK_MAX_SIGNAL_LATENCY_MS', 4000)),
        'webhookFallbackEnabled' => env('FUTURE_TRADE_WEBHOOK_FALLBACK_ENABLED', false),
        'primeMirrorLiveEnabled' => false,
        'validatedDefaultSymbols' => ['ETHUSDT', 'BTCUSDT', 'SOLUSDT'],
        'validationSymbols' => ['ETHUSDT', 'BTCUSDT', 'SOLUSDT', 'BNBUSDT'],
        // Curated default — covers the common Pine alert majors. Any symbol
        // in `available_symbols` is also accepted automatically unless the
        // operator flips `restrictToWorkspaceSymbols` to true.
        'symbols' => ['ETHUSDT', 'BTCUSDT', 'SOLUSDT', 'BNBUSDT'],
        // When true, only symbols in this workspace's curated `symbols` list
        // execute. When false (default), any symbol in the global
        // `available_symbols` registry is accepted from a Pine webhook so
        // TradingView alerts on majors don't silently 422.
        'restrictToWorkspaceSymbols' => false,
        // When true, TradingView Pine webhooks may execute any Binance-style
        // crypto futures pair ending in USDT/USDC (e.g. 1000PEPEUSDT.P after
        // normalization), even if the symbol is not in the curated UI list.
        'permissiveSymbolMode' => env('FUTURE_TRADE_PERMISSIVE_SYMBOL_MODE', true),
        'timeframe' => '15m',
        'paperTrading' => true,
        'liveTrading' => false,
        'forceTestnet' => true,
        'leverage' => 2,
        'marginMode' => 'isolated',
        'tradeAmountUsdt' => 25,
        'classicSimpleMode' => false,
        // Higher confidence threshold — only enter on strong signals
        'minConfidence' => 0.72,
        'tradeScoreThreshold' => 72,
        'watchScoreThreshold' => 58,
        'tradeDirection' => 'Both',
        'htf1' => '60',
        'htf2' => '240',
        'emaFast' => 9,
        'emaSlow' => 21,
        'emaTrend' => 50,
        'emaMajor' => 200,
        'donchianLength' => 20,
        'useVWAP' => true,
        'useDonchianScore' => true,
        'useVolume' => true,
        'useVolatilityFilter' => true,
        'useChopFilter' => true,
        'chopLength' => 14,
        'cryptoChopMax' => 61.8,
        'useMomentumBreakoutFilter' => false,
        'minBodyRatio' => 0.55,
        'rsiLength' => 14,
        'longRsiMin' => 48,
        'longRsiMax' => 72,
        'shortRsiMin' => 28,
        'shortRsiMax' => 52,
        'adxLength' => 14,
        'adxSmoothing' => 14,
        'adxMin' => 20,
        'volumeLength' => 20,
        'cryptoRelVolMin' => 1.00,
        'atrLength' => 14,
        'atrRegimeLength' => 50,
        'cryptoMinAtrPct' => 0.06,
        'cryptoMaxAtrPct' => 3.00,
        'slAtrMultiplier' => 1.4,
        'tp1RR' => 2.0,
        'tp2RR' => 2.0,
        'useAtrTrailingSecondHalf' => true,
        'trailAtrMultiplier' => 0.8,
        'useAtrRiskTargets' => env('FUTURE_TRADE_ATR_DYNAMIC_RISK_TARGETS', false),
        'useWebhookRiskOverrides' => env('FUTURE_TRADE_WEBHOOK_RISK_OVERRIDES', false),
        'confirmedCandleOnly' => env('FUTURE_TRADE_CONFIRMED_CANDLE_ONLY', true),
        'cooldownBars' => 3,
        'exitOnStrongOppositeScore' => true,
        'oppositeExitScore' => 78,
        // Prime default: webhook decides direction; these manual exits control risk.
        // Tuned so SL/TP clear round-trip taker fees (~0.08%) + normal 15m crypto
        // noise. Tight 0.25%/0.5% values were getting stopped out by noise+fees
        // before TP could fill. RR kept ~1:2 (0.75 SL : 1.5 TP).
        'takeProfitPercent' => 1.5,
        'stopLossPercent' => 0.75,
        // Trailing give-back from peak; only arms on the second half (see
        // useAtrTrailingSecondHalf) so it never closes before breakeven.
        'trailingTakeProfitPercent' => 0.45,
        // Soft early-bail kept INSIDE the hard SL but above noise+fees so it
        // is a cushion, not a noise trigger. Must stay < stopLossPercent.
        'rollbackLossPercent' => 0.6,
        'maxDailyLossPercent' => 2.0,
        // 3-tier daily-loss guard:
        //   <= softDailyLossWarnPercent      → log warning only, all trades allowed
        //   >= maxDailyLossPercent           → block new entries, allow exits / close webhooks
        //   >= hardDailyLossBlockPercent     → block all webhook-driven trades (entries AND exits)
        // Automated stop-loss / trailing exits run independently via the exit monitor.
        'softDailyLossWarnPercent' => 1.2,
        'hardDailyLossBlockPercent' => 3.0,
        // Start live safely with one active slot. Multi-slot live can be enabled
        // intentionally via allowMultiLiveSlots + FUTURE_TRADE_LIVE_MAX_OPEN_POSITIONS.
        'maxOpenPositions' => 1,
        'allowMultiLiveSlots' => false,
        'reversalMode' => 'close_only',
        // Webhook-only mode can accept more Pine entries; loss/cooldown/open-position guards still apply.
        'maxTradesPerDay' => 50,
        'maxLeverage' => 2,
        'maxSpreadPercent' => 0.12,
        // Longer cooldown prevents chasing failed trades
        'cooldownSeconds' => 180,
        'scanIntervalSeconds' => 3,
        'confidenceMode' => 'fixed',
        'dynamicLookbackMinutes' => 10,
        'dynamicMinConfidenceFloor' => 0.52,
        'dynamicMinConfidenceCeiling' => 0.64,
        'dynamicReentryBuffer' => 0.005,
        'dynamicSuggestionEnabled' => false,
        'autoExecutePaperSignals' => true,
        // signalSource, pineWebhookFallbackTimeoutSeconds, and
        // webhookFallbackEnabled are declared earlier in the defaults array
        // (above, around the env() block). They are NOT re-declared here to
        // keep this map duplicate-free. See top of `defaults` for the active
        // values that the bot reads at runtime.
        'primeMirrorExecutionEnabled' => false,
        'avoidChoppyMarket' => true,
        'strictEntryGuards' => true,
        'weakSetupAction' => 'reduce_size',
        'directionalMinScore5m' => 5,
        'directionalMinVoteGap5m' => 1,
        'corePassRule' => 3,
        'symbolPerfAdjustCap' => 0.04,
        'recentAccuracyLookbackTrades' => 20,
        'missedTradeAuditWindowMinutes' => 15,
        'missedTradeAuditReviewWindows' => [5, 10, 15, 30],
        'missedTradeAuditFeePercentPerSide' => 0.04,
        'missedTradeAuditRoundTripFeePercent' => 0.08,
        'missedTradeAuditSlippagePercentPerSide' => 0.015,
        'missedTradeAuditRoundTripSlippagePercent' => 0.03,
        'missedTradeAuditRoundTripCostPercent' => 0.11,
        'missedTradeAuditEstimatedFeePercent' => 0.08,
        'missedTradeAuditEstimatedSlippagePercent' => 0.03,
        'missedTradeAuditMinNetProfitPercent' => 0.05,
        'preset' => null,
        'emergencyStop' => false,
        // --- Trading-behaviour defaults that the bot previously read only via
        // `$settings[...] ?? <fallback>` (not declared here). Pinned explicitly
        // so the UI/DB always has a concrete starting value and behaviour does
        // not silently depend on code-level fallbacks. Values match the prior
        // code fallbacks, so this changes nothing functionally — it just makes
        // every knob visible and editable.
        // NOTE: API keys/secrets/URLs and the live/testnet env-mode flags are
        // intentionally NOT pinned here — those come from .env / config/services
        // so live↔testnet switching and credential rotation keep working. Do
        // not hardcode them in this defaults map.
        'coreOnlyMode' => false,
        'useLiveDataFeed' => true,
        'tradingHoursEnabled' => false,
        'tradingHoursStartUtc' => '00:00',
        'tradingHoursEndUtc' => '23:59',
        'researchLab' => [],
        'indicatorInfluence' => [
            'vwapPositive' => 7,
            'emaPositive' => 7,
            'adxPositive' => 6,
            'macdPositive' => 7,
            'rsiPositive' => 2,
            'volumePositive' => 2,
            'htfPositive' => 2,
            'macroPositive' => 2,
            'structurePositive' => 2,
            'sequencePositive' => 4,
            'extensionPositive' => 2,
            'symbolPerfPositive' => 1,
            'symbolPerfPenalty' => 1,
            'macdHistogramBonus' => 4,
            'directionalSignalBonus' => 5,
            'trendContinuationBonus' => 5,
            'volumePenalty' => 2,
            'macdPenalty' => 2,
            'htfPenalty' => 2,
            'structurePenalty' => 2,
            'extensionPenalty' => 2,
            'rsiPenalty' => 2,
            'choppyPenalty' => 2,
            'weakSetupPenalty' => 1,
            'recentAccuracyWeight' => 3,
            'lossStreakWeight' => 3,
            'atrWeight' => 3,
            'volatilityWeight' => 3,
        ],
    ],
];
