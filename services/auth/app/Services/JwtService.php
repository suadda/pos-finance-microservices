<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Issues and validates HS256 access/refresh tokens and maintains the revocation blacklist.
 */
class JwtService
{
    private const ALG = 'HS256';

    public function __construct(
        private readonly string $secret,
        private readonly string $issuer,
        private readonly int $accessTtl,
        private readonly int $refreshTtl,
    ) {}

    /** @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, refresh_expires_in: int} */
    public function issuePair(User $user): array
    {
        return [
            'access_token' => $this->encode($user, 'access', $this->accessTtl),
            'refresh_token' => $this->encode($user, 'refresh', $this->refreshTtl),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTtl,
            'refresh_expires_in' => $this->refreshTtl,
        ];
    }

    /**
     * Decodes a token, checks signature/expiry/type and the blacklist.
     *
     * @throws ApiException 401 when the token is not usable
     */
    public function verify(string $token, string $expectedType): object
    {
        try {
            $claims = JWT::decode($token, new Key($this->secret, self::ALG));
        } catch (ExpiredException) {
            throw new ApiException(401, 'TOKEN_EXPIRED', 'Token sudah kedaluwarsa');
        } catch (Throwable) {
            throw new ApiException(401, 'TOKEN_INVALID', 'Token tidak valid');
        }

        if (($claims->iss ?? null) !== $this->issuer || ($claims->type ?? null) !== $expectedType || empty($claims->jti)) {
            throw new ApiException(401, 'TOKEN_INVALID', 'Token tidak valid');
        }
        if ($this->isRevoked($claims->jti)) {
            throw new ApiException(401, 'TOKEN_REVOKED', 'Token sudah dicabut (logout)');
        }

        return $claims;
    }

    /** Adds the token's jti to the blacklist (idempotent). */
    public function revoke(object $claims): void
    {
        DB::table('token_blacklist')->insertOrIgnore([
            'jti' => $claims->jti,
            'token_type' => $claims->type,
            'user_id' => (int) $claims->sub,
            'expires_at' => Carbon::createFromTimestamp($claims->exp, config('app.timezone')),
            'created_at' => now(),
        ]);
    }

    /** Removes blacklist rows whose token would be expired anyway. */
    public function pruneExpired(): void
    {
        DB::table('token_blacklist')->where('expires_at', '<', now())->delete();
    }

    private function isRevoked(string $jti): bool
    {
        return DB::table('token_blacklist')->where('jti', $jti)->exists();
    }

    private function encode(User $user, string $type, int $ttl): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $this->issuer,
            'sub' => (string) $user->id,
            'type' => $type,
            'jti' => (string) Str::uuid(),
            'iat' => $now,
            'exp' => $now + $ttl,
            // Convenience claims for the SPA; downstream services always re-check via GET /auth/me.
            'name' => $user->name,
            'role' => $user->role,
            'outlet_id' => $user->outlet_id,
        ], $this->secret, self::ALG);
    }
}
