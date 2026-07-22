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

    'zalo_zns' => [
        'app_id'          => env('ZALO_APP_ID'),
        'secret_key'      => env('ZALO_SECRET_KEY'),
        'access_token'    => env('ZALO_ZNS_ACCESS_TOKEN'),
        'refresh_token'   => env('ZALO_ZNS_REFRESH_TOKEN'),
        'otp_template_id' => env('ZALO_ZNS_OTP_TEMPLATE_ID'),
        'no_driver_template_id' => env('ZALO_ZNS_NO_DRIVER_TEMPLATE_ID'),
        'admin_email'     => env('ZALO_ZNS_ADMIN_EMAIL'),
    ],


    'payos' => [
        'client_id'    => env('PAYOS_CLIENT_ID'),
        'api_key'      => env('PAYOS_API_KEY'),
        'checksum_key' => env('PAYOS_CHECKSUM_KEY'),
    ],

    'payos_payout' => [
        'client_id'    => env('PAYOS_PAYOUT_CLIENT_ID'),
        'api_key'      => env('PAYOS_PAYOUT_API_KEY'),
        'checksum_key' => env('PAYOS_PAYOUT_CHECKSUM_KEY'),
    ],

    'esms' => [
        'api_key'    => env('ESMS_API_KEY'),
        'secret_key' => env('ESMS_SECRET_KEY'),
    ],

    'google_maps' => [
        'api_key'       => env('GOOGLE_MAPS_API_KEY'),
        'directions_url' => 'https://maps.googleapis.com/maps/api/directions/json',
    ],

];
