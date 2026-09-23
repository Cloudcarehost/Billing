<?php

$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173')))));
if (env('APP_ENV') === 'local') {
    $origins = array_values(array_unique([
        ...$origins,
        'http://localhost:5173',
        'http://localhost:5174',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:5174',
    ]));
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
