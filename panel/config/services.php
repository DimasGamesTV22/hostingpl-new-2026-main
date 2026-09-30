<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Внешние OAuth-провайдеры (вход на сайт)
    |--------------------------------------------------------------------------
    | Ключи берутся из .env, включаются через config('hosting.auth.oauth')
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'vk' => [
        'client_id' => env('VK_CLIENT_ID'),
        'client_secret' => env('VK_CLIENT_SECRET'),
        'redirect' => env('VK_REDIRECT_URI', '/auth/vk/callback'),
        'version' => '5.199',
    ],

    'telegram' => [
        'bot_token' => env('GD_TELEGRAM_BOT_TOKEN'),
        'redirect' => env('TELEGRAM_REDIRECT_URI', '/auth/telegram/callback'),
    ],

    'tinkoff' => [
        'terminal_key' => env('GD_TINKOFF_TERMINAL_KEY'),
        'password' => env('GD_TINKOFF_PASSWORD'),
    ],
];
