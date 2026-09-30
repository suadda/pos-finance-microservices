<?php

return [
    'auth' => ['url' => env('AUTH_SERVICE_URL', 'http://auth-service:8000')],
    'finance' => ['url' => env('FINANCE_SERVICE_URL', 'http://finance-service:8000')],

    // Shared secret sent as X-Service-Key on service-to-service calls to /internal/*.
    'internal_key' => env('INTERNAL_API_KEY'),

    // Timeout (seconds) for every outbound service call.
    'http_timeout' => (float) env('SERVICE_HTTP_TIMEOUT', 3),
];
