<?php

return [
    'mode' => env('BILLING_MODE', 'sandbox'), // sandbox | live

    'checkout_urls' => [
        'stripe' => [
            'sandbox' => [
                'success_url' => env('STRIPE_CHECKOUT_SUCCESS_URL_SANDBOX'),
                'cancel_url' => env('STRIPE_CHECKOUT_CANCEL_URL_SANDBOX'),
            ],
            'live' => [
                'success_url' => env('STRIPE_CHECKOUT_SUCCESS_URL_LIVE'),
                'cancel_url' => env('STRIPE_CHECKOUT_CANCEL_URL_LIVE'),
            ],
        ],
    ],

    'webhook_tokens' => [
        'stripe' => [
            'sandbox' => env('STRIPE_WEBHOOK_TOKEN_SANDBOX'),
            'live' => env('STRIPE_WEBHOOK_TOKEN_LIVE'),
        ],
        'paypal' => [
            'sandbox' => env('PAYPAL_WEBHOOK_TOKEN_SANDBOX'),
            'live' => env('PAYPAL_WEBHOOK_TOKEN_LIVE'),
        ],
        'payoneer' => [
            'sandbox' => env('PAYONEER_WEBHOOK_TOKEN_SANDBOX'),
            'live' => env('PAYONEER_WEBHOOK_TOKEN_LIVE'),
        ],
    ],

    'signing_secrets' => [
        'stripe' => [
            'sandbox' => env('STRIPE_WEBHOOK_SECRET_SANDBOX'),
            'live' => env('STRIPE_WEBHOOK_SECRET_LIVE'),
        ],
        'paypal' => [
            'sandbox' => env('PAYPAL_WEBHOOK_SECRET_SANDBOX'),
            'live' => env('PAYPAL_WEBHOOK_SECRET_LIVE'),
        ],
        'payoneer' => [
            'sandbox' => env('PAYONEER_WEBHOOK_SECRET_SANDBOX'),
            'live' => env('PAYONEER_WEBHOOK_SECRET_LIVE'),
        ],
    ],

    'credentials' => [
        'payhere' => [
            'sandbox' => [
                'merchant_id' => env('PAYHERE_MERCHANT_ID_SANDBOX'),
                'merchant_secret' => env('PAYHERE_MERCHANT_SECRET_SANDBOX'),
                'redirect_url' => env('PAYHERE_REDIRECT_SANDBOX'),
            ],
            'live' => [
                'merchant_id' => env('PAYHERE_MERCHANT_ID_LIVE'),
                'merchant_secret' => env('PAYHERE_MERCHANT_SECRET_LIVE'),
                'redirect_url' => env('PAYHERE_REDIRECT_LIVE'),
            ],
        ],
        'stripe' => [
            'sandbox' => [
                'secret_key' => env('STRIPE_SECRET_KEY_SANDBOX'),
            ],
            'live' => [
                'secret_key' => env('STRIPE_SECRET_KEY_LIVE'),
            ],
        ],
        'paypal' => [
            'sandbox' => [
                'client_id' => env('PAYPAL_CLIENT_ID_SANDBOX'),
                'client_secret' => env('PAYPAL_CLIENT_SECRET_SANDBOX'),
            ],
            'live' => [
                'client_id' => env('PAYPAL_CLIENT_ID_LIVE'),
                'client_secret' => env('PAYPAL_CLIENT_SECRET_LIVE'),
            ],
        ],
        'payoneer' => [
            'sandbox' => [
                'program_id' => env('PAYONEER_PROGRAM_ID_SANDBOX'),
                'username' => env('PAYONEER_USERNAME_SANDBOX'),
                'password' => env('PAYONEER_PASSWORD_SANDBOX'),
                'checkout_url' => env('PAYONEER_CHECKOUT_URL_SANDBOX'),
            ],
            'live' => [
                'program_id' => env('PAYONEER_PROGRAM_ID_LIVE'),
                'username' => env('PAYONEER_USERNAME_LIVE'),
                'password' => env('PAYONEER_PASSWORD_LIVE'),
                'checkout_url' => env('PAYONEER_CHECKOUT_URL_LIVE'),
            ],
        ],
        'bank' => [
            'sandbox' => [
                'api_url' => env('BANK_PAYOUT_URL_SANDBOX'),
                'api_key' => env('BANK_PAYOUT_KEY_SANDBOX'),
            ],
            'live' => [
                'api_url' => env('BANK_PAYOUT_URL_LIVE'),
                'api_key' => env('BANK_PAYOUT_KEY_LIVE'),
            ],
        ],
    ],
];
