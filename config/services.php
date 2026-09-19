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

    'indexnow' => [
        'key' => env('INDEXNOW_KEY'),
        'endpoints' => array_values(array_filter(array_map('trim', explode(',', (string) env('INDEXNOW_ENDPOINTS', ''))))),
    ],

    // Inbound payment gateway webhook secrets. Each gateway key maps to the
    // HMAC-SHA256 secret used to authenticate /api/v1/payments/webhooks/{gateway}.
    'payment_sdk' => [
        'sandbox' => ['secret' => env('PAYMENT_SANDBOX_SECRET')],
        'midtrans' => ['secret' => env('PAYMENT_MIDTRANS_SECRET')],
        'xendit' => ['secret' => env('PAYMENT_XENDIT_SECRET')],
    ],

];
