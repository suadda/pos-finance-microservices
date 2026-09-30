<?php

return [
    // HS256 secret; must be at least 32 characters.
    'secret' => env('JWT_SECRET'),
    'issuer' => env('JWT_ISSUER', 'auth-service'),
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 900),       // seconds (15 minutes)
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 604800),  // seconds (7 days)
];
