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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'firebase' => [
        'credentials' => base_path(env('FIREBASE_CREDENTIALS', 'firebase_credentials.json')),
    ],

    'google' => [
        'places_key' => env('GOOGLE_MAPS_API_KEY'),

        /**
         * MUST be the WEB client id, not the iOS one.
         *
         * Socialite's Google driver verifies the identity token's `aud` claim
         * against this single value, and the mobile SDK requests its idToken
         * against the web client id on BOTH platforms (that is what
         * `webClientId` does). Putting the iOS id here rejects every sign-in.
         */
        'client_id'     => env('GOOGLE_WEB_CLIENT_ID'),
        // Unused by the native flow — Socialite requires the keys to exist.
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect'      => env('GOOGLE_REDIRECT_URI', ''),
    ],

    'apple' => [
        // For NATIVE Sign in with Apple the audience is the iOS bundle
        // identifier, not a services id (services ids are for web flows).
        'client_id'     => env('APPLE_CLIENT_ID', 'com.busaccess.client'),
        'client_secret' => env('APPLE_CLIENT_SECRET', ''),
        'redirect'      => env('APPLE_REDIRECT_URI', ''),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],

];
