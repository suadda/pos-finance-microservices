<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('role:superadmin,supervisor_pos') */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->attributes->get('user');
        if (! $user || ! in_array($user->role, $roles, true)) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
