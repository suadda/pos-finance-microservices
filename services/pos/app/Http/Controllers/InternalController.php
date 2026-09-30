<?php

namespace App\Http\Controllers;

use App\Services\EodSummaryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Service-to-service endpoints (X-Service-Key). Not routed by the API gateway. */
class InternalController
{
    /** GET /internal/eod-summary?outlet_id=&business_date= */
    public function eodSummary(Request $request, EodSummaryService $eod): JsonResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'integer', 'min:1'],
            'business_date' => ['required', 'date_format:Y-m-d'],
        ]);

        return ApiResponse::ok($eod->summary((int) $data['outlet_id'], $data['business_date']));
    }
}
