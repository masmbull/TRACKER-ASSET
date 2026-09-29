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

    // Microsoft Entra ID / Microsoft 365 SSO (PRD §12, §38).
    // Enabled only when ENTRA_ENABLED=true; otherwise local auth is used.
    'entra' => [
        'enabled' => env('ENTRA_ENABLED', false),
        'client_id' => env('ENTRA_CLIENT_ID'),
        'client_secret' => env('ENTRA_CLIENT_SECRET'),
        'tenant_id' => env('ENTRA_TENANT_ID', 'common'),
        'redirect' => env('ENTRA_REDIRECT_URI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin service monitor
    |--------------------------------------------------------------------------
    |
    | The health-endpoint probe makes a real HTTP request to APP_URL, which is
    | the right behaviour in production: an endpoint that answers internally but
    | is unreachable from outside is still broken. In the test suite it is the
    | wrong behaviour, because it makes "everything healthy" depend on whether
    | the developer happens to have a dev server running. Disabled there so the
    | suite measures this code rather than the workstation.
    |
    */
    'probe_health_endpoint' => env('SERVICES_PROBE_HEALTH_ENDPOINT', true),

];
