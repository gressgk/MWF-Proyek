<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        // Signature Secret Key — digunakan KHUSUS untuk verifikasi signature webhook Midtrans.
        // Berbeda dengan Server Key API yang digunakan untuk Snap dan Get Status API.
        // Lihat: Midtrans Dashboard → Settings → Access Keys → Signature Secret Key
        'signature_secret_key' => env('MIDTRANS_SIGNATURE_SECRET_KEY'),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
        'snap_url' => env('MIDTRANS_IS_PRODUCTION', false)
            ? 'https://app.midtrans.com/snap/snap.js'
            : 'https://app.sandbox.midtrans.com/snap/snap.js',
        'api_url' => env('MIDTRANS_IS_PRODUCTION', false)
            ? 'https://app.midtrans.com/snap/v1'
            : 'https://app.sandbox.midtrans.com/snap/v1',
        'status_api_url' => env('MIDTRANS_IS_PRODUCTION', false)
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2',
        'subscription_api_url' => env('MIDTRANS_IS_PRODUCTION', false)
            ? 'https://api.midtrans.com/v1/subscriptions'
            : 'https://api.sandbox.midtrans.com/v1/subscriptions',
        // Batas nominal sementara (menunggu ketentuan resmi dari PM / Pengaturan Admin WeCare)
        'min_amount' => (int) env('MIDTRANS_MIN_AMOUNT', 1000),
        'max_amount' => (int) env('MIDTRANS_MAX_AMOUNT', 100000000),
        'observability' => [
            'enabled' => filter_var(env('MIDTRANS_OBSERVABILITY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'channel' => env('MIDTRANS_OBSERVABILITY_LOG_CHANNEL', 'midtrans_observability'),
            'allowed_environments' => ['local', 'testing'],
        ],
    ],

];
