<?php

return [
    'ai_trading' => [
        'provider' => env('AI_TRADE_PROVIDER', 'heuristic'),
        'proposal_expiry_minutes' => (int) env('AI_TRADE_PROPOSAL_EXPIRY_MINUTES', 15),
        'default_quote_currency' => env('AI_TRADE_QUOTE_CURRENCY', 'USDT'),
        'openai' => [
            'api_key' => env('AI_TRADE_OPENAI_API_KEY', env('OPENAI_API_KEY')),
            'api_url' => env('AI_TRADE_OPENAI_API_URL', env('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions')),
            'model' => env('AI_TRADE_OPENAI_MODEL', env('OPENAI_MODEL', 'gpt-4.1-mini')),
            'timeout_ms' => (int) env('AI_TRADE_OPENAI_TIMEOUT_MS', 5000),
        ],
        'gemini' => [
            'api_key' => env('AI_TRADE_GEMINI_API_KEY', env('GEMINI_API_KEY')),
            'api_url' => env('AI_TRADE_GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
            'model' => env('AI_TRADE_GEMINI_MODEL', env('GEMINI_MODEL', 'gemini-1.5-flash')),
            'timeout_ms' => (int) env('AI_TRADE_GEMINI_TIMEOUT_MS', 5000),
        ],
        'anthropic' => [
            'api_key' => env('AI_TRADE_ANTHROPIC_API_KEY', env('ANTHROPIC_API_KEY')),
            'api_url' => env('AI_TRADE_ANTHROPIC_API_URL', env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages')),
            'model' => env('AI_TRADE_ANTHROPIC_MODEL', env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest')),
            'timeout_ms' => (int) env('AI_TRADE_ANTHROPIC_TIMEOUT_MS', 5000),
        ],
        'heuristic' => [
            'min_trade_confidence' => (float) env('AI_TRADE_HEURISTIC_MIN_CONFIDENCE', 0.55),
            'strong_trade_confidence' => (float) env('AI_TRADE_HEURISTIC_STRONG_CONFIDENCE', 0.65),
            'rsi_buy_ceiling' => (float) env('AI_TRADE_HEURISTIC_RSI_BUY_CEILING', 68),
            'rsi_sell_floor' => (float) env('AI_TRADE_HEURISTIC_RSI_SELL_FLOOR', 32),
            'default_stop_loss_pct' => (float) env('AI_TRADE_HEURISTIC_STOP_LOSS_PCT', 0.8),
            'default_take_profit_pct' => (float) env('AI_TRADE_HEURISTIC_TAKE_PROFIT_PCT', 1.6),
            'default_time_stop_minutes' => (int) env('AI_TRADE_HEURISTIC_TIME_STOP_MINUTES', 90),
        ],
    ],
    'external_context' => [
        'enabled_by_default' => (bool) env('EXTERNAL_CONTEXT_ENABLED', false),
        'max_items' => (int) env('EXTERNAL_CONTEXT_MAX_ITEMS', 40),
        'max_age_minutes' => (int) env('EXTERNAL_CONTEXT_MAX_AGE_MINUTES', 60),
        'timeout_ms' => (int) env('EXTERNAL_CONTEXT_TIMEOUT_MS', 3500),
        'news' => [
            'provider' => env('EXTERNAL_NEWS_PROVIDER', 'rss'),
            'rss_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('EXTERNAL_NEWS_RSS_URLS', ''))))),
            'max_freshness_minutes' => (int) env('EXTERNAL_NEWS_MAX_FRESHNESS_MINUTES', 180),
        ],
        'macro' => [
            'provider' => env('EXTERNAL_MACRO_PROVIDER', 'null'),
            'json_url' => env('EXTERNAL_MACRO_JSON_URL', ''),
        ],
        'exchange' => [
            'provider' => env('EXTERNAL_EXCHANGE_PROVIDER', 'null'),
            'json_url' => env('EXTERNAL_EXCHANGE_JSON_URL', ''),
        ],
    ],
];
