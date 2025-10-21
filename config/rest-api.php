<?php

return [
    'rest_api' => [
        'enabled' => env('REST_API_ENABLED', true),
        'timeout' => env('REST_API_TIMEOUT', 30),
        'verify_ssl' => env('REST_API_VERIFY_SSL', false),
        'max_retries' => env('REST_API_MAX_RETRIES', 3),
        'poll_interval' => env('REST_API_POLL_INTERVAL', 300),
    ],
];
