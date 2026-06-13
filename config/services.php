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

    'zoom' => [
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        'host_user_id' => env('ZOOM_HOST_USER_ID', 'me'),
    ],

    'pathways_webinar' => [
        'timezone' => env('PATHWAYS_TIMEZONE', 'Africa/Kigali'),
        'zoom_join_url' => env('PATHWAYS_ZOOM_JOIN_URL', 'https://us06web.zoom.us/j/84024505834?pwd=S35BVbbF5OO8zY1zBMIw59YKw3L5Gx.1'),
        'zoom_meeting_id' => env('PATHWAYS_ZOOM_MEETING_ID'),
        'zoom_start_url' => env('PATHWAYS_ZOOM_START_URL'),
    ],

    'stripe' => [
        // Using keys already defined in .env
        'secret' => env('STRIPE_SECRET_KEY'),
        'key'    => env('STRIPE_PUBLIC_KEY'),
    ],

    'pcloud' => [
        'access_token' => env('PCLOUD_ACCESS_TOKEN'),
        'root_folder' => env('PCLOUD_ROOT_FOLDER', 'parrotacademy'),
        'base_url' => env('PCLOUD_API_URL', 'https://api.pcloud.com'),
    ],

];
