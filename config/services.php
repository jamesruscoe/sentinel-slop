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

    /*
    | GitHub App. Socialite uses client_id/client_secret/redirect for login;
    | the App services use app_id, private_key_path and webhook_secret.
    */
    'github' => [
        'client_id' => env('GITHUB_APP_CLIENT_ID'),
        'client_secret' => env('GITHUB_APP_CLIENT_SECRET'),
        'redirect' => env('GITHUB_APP_REDIRECT_URI', '/auth/github/callback'),
        'app_id' => env('GITHUB_APP_ID'),
        'app_slug' => env('GITHUB_APP_SLUG'),
        'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
        // The PEM itself (raw or base64-encoded), for environments that inject secrets as variables. Wins over the path.
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
