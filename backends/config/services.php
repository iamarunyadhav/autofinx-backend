<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'binance' => [
        'api_url' => env('BINANCE_API_URL', 'https://api.binance.com'),
        'ws_url' => env('BINANCE_WS_URL', 'wss://stream.binance.com:9443'),
        'testnet_api_url' => env('BINANCE_TESTNET_API_URL', 'https://testnet.binance.vision'),
        'testnet_ws_url' => env('BINANCE_TESTNET_WS_URL', 'wss://testnet.binance.vision'),
        'futures_api_url' => env('BINANCE_FUTURES_API_URL', 'https://fapi.binance.com'),
        'futures_testnet_api_url' => env('BINANCE_FUTURES_TESTNET_API_URL', 'https://testnet.binancefuture.com'),
    ],

    'metal_broker' => [
        'mode' => env('METAL_BROKER_MODE', 'sandbox'),
        'api_url' => env('METAL_BROKER_API_URL'),
        'api_key' => env('METAL_BROKER_API_KEY'),
        'api_secret' => env('METAL_BROKER_API_SECRET'),
        'order_path' => env('METAL_BROKER_ORDER_PATH', '/v1/orders'),
        'status_path' => env('METAL_BROKER_STATUS_PATH', '/v1/orders/{order_id}'),
        'balances_path' => env('METAL_BROKER_BALANCES_PATH', '/v1/account/balances'),
        'price_path' => env('METAL_BROKER_PRICE_PATH', '/v1/quotes/{asset_code}'),
        'supports_ioc' => (bool) env('METAL_BROKER_SUPPORTS_IOC', false),
        'default_spread_pct' => (float) env('METAL_BROKER_DEFAULT_SPREAD_PCT', 0.4),
    ],

    'fx' => [
        'provider' => env('FX_RATE_PROVIDER', 'openexchangerates'),
        'api_url' => env('FX_RATE_API_URL'),
        'api_key' => env('FX_RATE_API_KEY'),
        'ttl_sec' => (int) env('FX_RATE_TTL_SEC', 120),
        'default_usd_lkr' => (float) env('FX_DEFAULT_USD_LKR', 315),
    ],

    'ai' => [
        'enabled' => (bool) env('AI_BRAIN_ENABLED', false),
        'provider' => env('AI_BRAIN_PROVIDER', 'openai'),
        'timeout_ms' => (int) env('AI_BRAIN_TIMEOUT_MS', 3500),
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'api_url' => env('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions'),
            'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'api_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'),
        ],
    ],

];
