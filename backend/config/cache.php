<?php

return [

    'default' => env('CACHE_STORE', 'database'),

    'stores' => [

        'array' => [
            'driver'    => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver'          => 'database',
            'connection'      => env('DB_CACHE_CONNECTION'),
            'table'           => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table'      => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path'   => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'redis' => [
            'driver'     => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

    ],

    'prefix' => env('CACHE_PREFIX', 'psm_cache_'),

    /*
    |--------------------------------------------------------------------------
    | Domain caches
    |--------------------------------------------------------------------------
    | TTLs (seconds) for computed values that are expensive on MySQL.
    */
    'ttl' => [
        'leaderboard'      => (int) env('CACHE_TTL_LEADERBOARD', 300),
        'cohort_progress'  => (int) env('CACHE_TTL_COHORT_PROGRESS', 120),
        'workload_stats'   => (int) env('CACHE_TTL_WORKLOAD_STATS', 300),
    ],

];
