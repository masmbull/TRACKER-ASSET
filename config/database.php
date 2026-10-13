<?php

return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'tracker_asset'),
            'username' => env('DB_USERNAME', 'tracker'),
            'password' => env('DB_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
    ],

    'redis' => [
        'client' => 'phpredis',
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', 'tracker-asset'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        // Laravel ships two Redis connections - `default` and `cache` - and the
        // cache config resolves REDIS_CACHE_CONNECTION to `cache` by default.
        // Only `default` was declared here, so any deployment with
        // CACHE_STORE=redis died on "Redis connection [cache] not configured":
        // the store asked for a connection the file never defined. Local
        // development never saw it because it caches to file.
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],
];
