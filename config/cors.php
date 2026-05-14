<?php

return [
    'paths' => ['v1/*', 'api/v1/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('FRONTEND_APP_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Correlation-ID'],

    'max_age' => 0,

    'supports_credentials' => true,
];