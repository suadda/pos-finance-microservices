<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** HTTP client for Finance Service /internal/* (3s timeout, never throws). */
class FinanceClient
{
    /**
     * @return array{ok: bool, status: ?int, error: ?string, unreachable: bool}
     */
    public function post(array $payload): array
    {
        try {
            $response = Http::baseUrl(config('services.finance.url'))
                ->timeout(config('services.http_timeout'))
                ->connectTimeout(config('services.http_timeout'))
                ->acceptJson()
                ->withHeaders(['X-Service-Key' => config('services.internal_key')])
                ->post('/internal/postings', $payload);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'status' => null, 'unreachable' => true, 'error' => 'Finance Service tidak dapat dihubungi: '.Str::limit($e->getMessage(), 200)];
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => null, 'unreachable' => true, 'error' => 'Gagal memanggil Finance Service: '.Str::limit($e->getMessage(), 200)];
        }

        if ($response->successful()) { // 201 created or 200 duplicate (idempotent)
            return ['ok' => true, 'status' => $response->status(), 'unreachable' => false, 'error' => null];
        }

        $message = $response->json('error.message') ?? Str::limit($response->body(), 200);

        return [
            'ok' => false,
            'status' => $response->status(),
            'unreachable' => $response->serverError(),
            'error' => "Finance Service menolak ({$response->status()}): {$message}",
        ];
    }
}
