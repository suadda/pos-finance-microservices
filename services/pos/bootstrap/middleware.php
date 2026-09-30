<?php

use App\Http\Middleware\AuthenticateViaAuthService;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\VerifyServiceKey;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

/** @var Middleware $middleware */
$middleware->alias([
    'jwt' => AuthenticateViaAuthService::class,
    'role' => RequireRole::class,
    'service.key' => VerifyServiceKey::class,
]);

// Authenticate/authorise before route-model binding, so unauthenticated callers get 401/403, never 404.
foreach ([VerifyServiceKey::class, AuthenticateViaAuthService::class, RequireRole::class] as $guard) {
    $middleware->prependToPriorityList(SubstituteBindings::class, $guard);
}
