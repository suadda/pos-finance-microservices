<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\AuthUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('role:kasir,superadmin') */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->attributes->get('auth_user');
        if (! $user instanceof AuthUser || ! $user->is(...$roles)) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
