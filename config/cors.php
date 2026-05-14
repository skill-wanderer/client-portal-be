<?php

$configuredOrigins = str_getcsv((string) env('CORS_ALLOWED_ORIGINS', ''), ',', '"', '\\');
$allowedOrigins = array_values(array_filter(
    array_map(
        static fn ($origin): string => is_string($origin) ? rtrim(trim($origin), '/') : '',
        $configuredOrigins,
    ),
    static fn (string $origin): bool => $origin !== '',
));

if ($allowedOrigins === []) {
    $defaultOrigins = [
        rtrim((string) env('FRONTEND_APP_URL', 'http://127.0.0.1:3000'), '/'),
        'http://127.0.0.1:3000',
        'http://localhost:3000',
    ];

    $allowedOrigins = array_values(array_unique(array_filter($defaultOrigins, static fn (string $origin): bool => $origin !== '')));
}

return [
    'paths' => ['v1/*', 'api/v1/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Correlation-ID'],

    'max_age' => 0,

    'supports_credentials' => true,
];