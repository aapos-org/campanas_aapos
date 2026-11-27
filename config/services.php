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
        // Secret part of the webhook URL: /webhooks/elasticemail/{token}. Generate with: php artisan app:webhook-token
        'webhook_token' => env('ELASTICEMAIL_WEBHOOK_TOKEN'),
    ],

    // Public API (/api/v1): requests per minute per token.
    'api' => [
        'rate_per_minute' => (int) env('API_RATE_PER_MINUTE', 120),
    ],

    // Writing assistant (Anthropic). Only non-secret defaults live here: the API key, model
    // and behaviour are configured per organization in Administración → Asistente de IA.
    'anthropic' => [
        'base_url' => 'https://api.anthropic.com',
        'version' => '2026-10-21',
        'suggested_models' => [
            ['id' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5 (recomendado: mejor calidad)'],
            ['id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5 (equilibrio entre calidad y costo)'],
            ['id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5 (el más rápido y económico)'],
        ],
    ],

];
