<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter([
        env('FRONTEND_URL'),
        'http://127.0.0.1:5174',
        'http://localhost:5174',
        'http://127.0.0.1:5173',
        'http://localhost:5173',
        'http://127.0.0.1:8080',
        'http://localhost:8080',
    ]))),

    'allowed_origins_patterns' => [
        '#^https?://.*#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
