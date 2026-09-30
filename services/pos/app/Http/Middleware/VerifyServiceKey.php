<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Guards /internal/* : only other services holding INTERNAL_API_KEY may call them. */
class VerifyServiceKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.internal_key');
        $given = (string) $request->header('X-Service-Key', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            throw ApiException::forbidden('Service key tidak valid');
        }

        return $next($request);
    }
}
