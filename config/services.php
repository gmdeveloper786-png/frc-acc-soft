<?php

return [

    'frc' => [
        'enabled' => env('FRC_API_ENABLED', true),
        'base_url' => env('FRC_API_BASE_URL', 'http://127.0.0.1:8001/api'),
        'branches_endpoint' => env('FRC_API_BRANCHES_ENDPOINT', 'integrations/accounting/v1/branches'),
        'services_endpoint' => env('FRC_API_SERVICES_ENDPOINT', 'integrations/accounting/v1/services'),
        'token' => env('FRC_API_TOKEN'),
        'timeout' => (int) env('FRC_API_TIMEOUT', 15),
        'connect_timeout' => (int) env('FRC_API_CONNECT_TIMEOUT', 5),
        'retry_times' => (int) env('FRC_API_RETRY_TIMES', 2),
        'retry_delay_ms' => (int) env('FRC_API_RETRY_DELAY_MS', 200),
    ],

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

];
