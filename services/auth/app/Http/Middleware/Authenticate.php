<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Validates the Bearer access token and loads the (active) user into the request. */
class Authenticate
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            throw ApiException::unauthorized('Header Authorization Bearer wajib diisi');
        }

        $claims = $this->jwt->verify($token, 'access');
        $user = User::find((int) $claims->sub);
        if (! $user) {
            throw ApiException::unauthorized('User tidak ditemukan');
        }
        if (! $user->is_active) {
            throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Akun nonaktif');
        }

        $request->attributes->set('user', $user);
        $request->attributes->set('claims', $claims);

        return $next($request);
    }
}
