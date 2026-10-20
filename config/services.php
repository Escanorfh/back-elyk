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

    'livekit' => [
        'url' => env('LIVEKIT_URL', 'http://127.0.0.1:7880'),
        'ws_url' => env('LIVEKIT_WS_URL', 'ws://127.0.0.1:7880'),
        'api_key' => env('LIVEKIT_API_KEY', 'devkey'),
        'api_secret' => env('LIVEKIT_API_SECRET', 'super_secret_key_that_is_at_least_32_bytes_long'),
    ],

    'fawry' => [
        'merchant_code' => env('FAWRY_MERCHANT_CODE'),
        'security_key' => env('FAWRY_SECURITY_KEY'),
        'base_url' => env('FAWRY_BASE_URL', 'https://atfawry.fawrystaging.com'),
        'return_url' => env('FAWRY_RETURN_URL'),
        // Optional comma-separated IP allowlist for webhooks. Empty = open.
        'webhook_ips' => env('FAWRY_WEBHOOK_IPS', ''),
    ],

    'instapay' => [
        // 'aggregator': EBC-certified aggregator rail | 'direct': clinic IPA + reference mode.
        'mode' => env('INSTAPAY_MODE', 'direct'),
        'clinic_ipa' => env('INSTAPAY_CLINIC_IPA'),
        // Static doctor payment link (e.g. https://ipn.eg/S/...). When set,
        // patients open it directly instead of a generated deep link.
        'payment_link' => env('INSTAPAY_PAYMENT_LINK'),
        'aggregator_base_url' => env('INSTAPAY_AGGREGATOR_BASE_URL'),
        'aggregator_api_key' => env('INSTAPAY_AGGREGATOR_API_KEY'),
        'webhook_secret' => env('INSTAPAY_WEBHOOK_SECRET'),
        'return_url' => env('INSTAPAY_RETURN_URL'),
        // Optional comma-separated IP allowlist for webhooks. Empty = open.
        'webhook_ips' => env('INSTAPAY_WEBHOOK_IPS', ''),
    ],

];
