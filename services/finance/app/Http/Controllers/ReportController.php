<?php

namespace App\Http\Controllers;

use App\Services\LedgerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController
{
    /** GET /reports/daily-sales?outlet_id=&business_date= — net per method from the ledger (outlet_id optional = all outlets). */
    public function dailySales(Request $request, LedgerService $ledger): JsonResponse
    {
        $data = $request->validate([
            'outlet_id' => ['nullable', 'integer', 'min:1'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $date = $data['business_date'] ?? now()->toDateString();
        $outletId = isset($data['outlet_id']) ? (int) $data['outlet_id'] : null;

        return ApiResponse::ok([
            'outlet_id' => $outletId,
            'business_date' => $date,
            ...$ledger->dailyNet($outletId, $date),
        ]);
    }
}
