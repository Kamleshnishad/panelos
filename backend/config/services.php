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

    'stripe' => [
        'public' => env('STRIPE_PUBLIC_KEY'),
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // e-Invoice GSP (IRP). Set EINVOICE_GSP_ENABLED=true when you have a GSP contract.
    'einvoice' => [
        'enabled'       => env('EINVOICE_GSP_ENABLED', false),
        'url'           => env('EINVOICE_GSP_URL'),           // GSP IRN API base URL
        'eway_url'      => env('EINVOICE_GSP_EWAY_URL'),      // GSP e-Way Bill API base URL
        'client_id'     => env('EINVOICE_GSP_CLIENT_ID'),
        'client_secret' => env('EINVOICE_GSP_CLIENT_SECRET'),
        'gstin'         => env('EINVOICE_GSP_GSTIN'),         // GSTIN of the registered taxpayer
        'username'      => env('EINVOICE_GSP_USERNAME'),
        'password'      => env('EINVOICE_GSP_PASSWORD'),
    ],

    'twilio' => [
        'enabled' => env('TWILIO_ENABLED', false),
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
        // WhatsApp (Twilio whatsapp: channel) — enable when a sender is provisioned
        'whatsapp_enabled' => env('TWILIO_WHATSAPP_ENABLED', false),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

];
