<?php

return [

    'name'     => env('APP_NAME', 'PSM Management System'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Kuala_Lumpur'),
    'locale'   => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale'    => env('APP_FAKER_LOCALE', 'en_US'),

    'cipher' => 'AES-256-CBC',
    'key'    => env('APP_KEY'),
    'previous_keys' => array_filter(
        explode(',', env('APP_PREVIOUS_KEYS', ''))
    ),

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store'  => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PSM domain configuration
    |--------------------------------------------------------------------------
    */
    'frontend_url'  => env('FRONTEND_URL', 'http://localhost:5173'),
    'leaderboard'   => [
        'base_path'        => env('LEADERBOARD_BASE_PATH', '/leaderboard'),
        'top_n'            => (int) env('LEADERBOARD_TOP_N', 3),
        'min_assessors'    => (int) env('LEADERBOARD_MIN_ASSESSORS', 2),
        'publish_delay_days' => (int) env('LEADERBOARD_PUBLISH_DELAY_DAYS', 0),
    ],

    'password_reset_expire' => (int) env('PASSWORD_RESET_EXPIRE_MINUTES', 60),

];
