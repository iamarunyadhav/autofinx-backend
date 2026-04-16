<?php

return [
    // Cron expression for background sync
    'sync_cron' => env('EXCHANGE_SYNC_CRON', '*/10 * * * *'),

    // Default currencies we refresh for demo/sandbox
    'currencies' => ['USDT', 'BTC', 'ETH'],

    // Symbols to keep positions refreshed
    'position_symbols' => ['BTCUSDT', 'ETHUSDT'],
];
