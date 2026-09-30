<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\JwtService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController
{
    public function __construct(private readonly JwtService $jwt) {}

    /** POST /auth/login */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'Email atau password salah');
        }
        if (! $user->is_active) {
            throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Akun nonaktif');
        }

        return ApiResponse::ok([...$this->jwt->issuePair($user), 'user' => $user->toIdentity()]);
    }

    /** POST /auth/refresh — rotates the refresh token (old one is blacklisted). */
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string']]);

        $claims = $this->jwt->verify($data['refresh_token'], 'refresh');
        $user = User::find((int) $claims->sub);
        if (! $user || ! $user->is_active) {
            throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Akun nonaktif atau tidak ditemukan');
        }

        $pair = DB::transaction(function () use ($claims, $user) {
            $this->jwt->revoke($claims);

            return $this->jwt->issuePair($user);
        });

        return ApiResponse::ok([...$pair, 'user' => $user->toIdentity()]);
    }

    /**
     * POST /auth/logout — blacklists the refresh token and, when present and still valid,
     * the current access token too. Idempotent.
     */
    public function logout(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string']]);

        try {
            $this->jwt->revoke($this->jwt->verify($data['refresh_token'], 'refresh'));
        } catch (ApiException $e) {
            if ($e->errorCode !== 'TOKEN_REVOKED') {
                throw $e;
            }
        }

        if ($access = $request->bearerToken()) {
            try {
                $this->jwt->revoke($this->jwt->verify($access, 'access'));
            } catch (ApiException) {
                // Expired/invalid access token: nothing to revoke.
            }
        }

        $this->jwt->pruneExpired();

        return ApiResponse::ok(['message' => 'Logout berhasil']);
    }

    /** GET /auth/me — used by the SPA and by POS/Finance to validate every request. */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok($request->attributes->get('user')->toIdentity());
    }
}
