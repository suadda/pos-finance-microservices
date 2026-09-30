<?php

namespace App\Providers;

use App\Services\JwtService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JwtService::class, function () {
            $secret = (string) config('jwt.secret');
            if (strlen($secret) < 32) {
                throw new RuntimeException('JWT_SECRET must be set and at least 32 characters long');
            }

            return new JwtService($secret, config('jwt.issuer'), config('jwt.access_ttl'), config('jwt.refresh_ttl'));
        });
    }

    public function boot(): void
    {
        // Brute-force protection per account + client IP (client IP comes from the gateway's X-Forwarded-For).
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip())
            ->response(fn () => response()->json(
                ['error' => ['code' => 'TOO_MANY_ATTEMPTS', 'message' => 'Terlalu banyak percobaan login, coba lagi dalam 1 menit']],
                429,
            )));
    }
}
