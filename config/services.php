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

    'firebase' => [
        'database_url' => env('FIREBASE_DATABASE_URL'),
    ],

    'apns' => [
        'team_id'     => env('APNS_TEAM_ID'),
        'key_id'      => env('APNS_KEY_ID'),
        'bundle_id'   => env('APNS_BUNDLE_ID', 'vn.flashship.driver'),
        'production'  => env('APNS_PRODUCTION', false),
        'private_key' => env('APNS_PRIVATE_KEY'),
    ],

    'vietmap' => [
        'api_key'    => env('VIETMAP_API_KEY'),
        'geocode_url' => 'https://maps.vietmap.vn/api/search/v3',
        'reverse_url' => 'https://maps.vietmap.vn/api/reverse/v3',
    ],

    'google_maps' => [
        'api_key'       => env('GOOGLE_MAPS_API_KEY'),
        'directions_url' => 'https://maps.googleapis.com/maps/api/directions/json',
    ],

];
