<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\TransactionService;
use App\Support\AccessScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController
{
    /** GET /dashboard?business_date=YYYY-MM-DD&outlet_id= — today's figures within the caller's data scope. */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'outlet_id' => ['nullable', 'integer'],
        ]);
        $date = $data['business_date'] ?? now()->toDateString();

        $query = AccessScope::apply(Transaction::query(), $request->attributes->get('auth_user'))
            ->whereDate('business_date', $date);
        if (isset($data['outlet_id'])) {
            $query->where('outlet_id', $data['outlet_id']);
        }

        return ApiResponse::ok(['business_date' => $date, ...TransactionService::aggregate($query)]);
    }
}
