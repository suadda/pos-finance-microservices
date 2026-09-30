<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RequireRole;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

/** @var Middleware $middleware */
$middleware->alias([
    'jwt' => Authenticate::class,
    'role' => RequireRole::class,
]);

// Requests arrive through the Nginx gateway on the private Docker network.
$middleware->trustProxies(at: '*');

// Authenticate/authorise before route-model binding, so unauthenticated callers get 401/403, never 404.
foreach ([Authenticate::class, RequireRole::class] as $guard) {
    $middleware->prependToPriorityList(SubstituteBindings::class, $guard);
}
