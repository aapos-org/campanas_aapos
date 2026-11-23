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

    // Gmail OAuth (Google Cloud project, OAuth client of type "Web application", internal app on Workspace).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Defaults to route('connections.google.callback'); must be registered in Google Cloud.
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Mailchimp migration (read-only use). Dedicated key; revoke it after the migration.
    'mailchimp' => [
        'api_key' => env('MAILCHIMP_API_KEY'),
    ],

    // Cloudflare Turnstile (anti-bot for public forms). Optional.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'elasticemail' => [
        'base_url' => env('ELASTICEMAIL_BASE_URL', 'https://api.elasticemail.com/v4'),
    ],

];
