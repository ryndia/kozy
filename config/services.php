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

    // MCB's online payment gateway (Mastercard Gateway). Not wired up yet -
    // card payments are simulated until it is. See MCB_INTEGRATION_TODO.md.
    'mcb' => [
        'gateway_url' => env('MCB_GATEWAY_URL'),
        'merchant_id' => env('MCB_MERCHANT_ID'),
        'api_password' => env('MCB_API_PASSWORD'),
        'api_version' => env('MCB_API_VERSION'),
        'webhook_secret' => env('MCB_WEBHOOK_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
