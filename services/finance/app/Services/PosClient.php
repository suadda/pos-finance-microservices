<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** HTTP client for POS Service /internal/* (3s timeout). */
class PosClient
{
    public function eodSummary(int $outletId, string $businessDate): array
    {
        try {
            $response = Http::baseUrl(config('services.pos.url'))
                ->timeout(config('services.http_timeout'))
                ->connectTimeout(config('services.http_timeout'))
                ->acceptJson()
                ->withHeaders(['X-Service-Key' => config('services.internal_key')])
                ->get('/internal/eod-summary', ['outlet_id' => $outletId, 'business_date' => $businessDate]);
        } catch (ConnectionException) {
            throw ApiException::unavailable('POS Service tidak dapat dihubungi; rekonsiliasi belum dapat dijalankan');
        }

        if ($response->status() === 404) {
            throw ApiException::unprocessable('OUTLET_NOT_FOUND', 'Outlet tidak ditemukan di POS');
        }
        if (! $response->successful()) {
            throw ApiException::unavailable('POS Service mengembalikan status '.$response->status());
        }

        return $response->json('data');
    }
}
