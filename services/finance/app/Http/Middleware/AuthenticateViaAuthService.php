<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\AuthUser;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the Bearer token on every request by calling the Auth Service (GET /auth/me).
 * The Auth Service is the single source of truth: revoked tokens and deactivated users are rejected there.
 */
class AuthenticateViaAuthService
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            throw ApiException::unauthorized('Header Authorization Bearer wajib diisi');
        }

        try {
            $response = Http::baseUrl(config('services.auth.url'))
                ->timeout(config('services.http_timeout'))
                ->acceptJson()
                ->withToken($token)
                ->get('/auth/me');
        } catch (ConnectionException) {
            throw ApiException::unavailable('Auth Service tidak dapat dihubungi');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new ApiException(
                401,
                $response->json('error.code', 'UNAUTHORIZED'),
                $response->json('error.message', 'Token tidak valid'),
            );
        }
        if (! $response->successful() || ! $response->json('data.is_active')) {
            throw $response->successful()
                ? new ApiException(401, 'ACCOUNT_INACTIVE', 'Akun nonaktif')
                : ApiException::unavailable('Auth Service mengembalikan status '.$response->status());
        }

        $request->attributes->set('auth_user', AuthUser::fromArray($response->json('data')));

        return $next($request);
    }
}
