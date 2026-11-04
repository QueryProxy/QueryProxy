<?php

use App\Support\SecretFile;

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
            'bot_user_oauth_token' => SecretFile::get('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],

        // The QueryProxy Slack app (ADR-027). One Slack app serves every team
        // of the installation: the signing secret verifies each request Slack
        // sends, the client id and secret run the OAuth v2 install.
        'app' => [
            'client_id' => env('QUERYPROXY_SLACK_CLIENT_ID'),
            'client_secret' => env('QUERYPROXY_SLACK_CLIENT_SECRET'),
            'signing_secret' => env('QUERYPROXY_SLACK_SIGNING_SECRET'),
        ],
    ],

];
