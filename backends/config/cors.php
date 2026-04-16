<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure SPA origins for credentialed requests. Do not use wildcard
    | origins when supports_credentials is true.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', ''))),
        [
            env('FRONTEND_URL', 'http://localhost:8080'),
            env('FRONTEND_URL_ALT', 'http://localhost:3000'),
            'https://autofinx.arunyadhav.dev',
            'http://localhost:8080',
            'http://localhost:3000',
        ],
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
