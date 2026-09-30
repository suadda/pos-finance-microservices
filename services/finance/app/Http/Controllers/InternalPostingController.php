<?php

namespace App\Http\Controllers;

use App\Services\PostingService;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Service-to-service endpoint (X-Service-Key). Not routed by the API gateway. */
class InternalPostingController
{
    private const MONEY_RULE = ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'];

    /** POST /internal/postings — 201 when new, 200 when duplicate (no second journal). */
    public function store(Request $request, PostingService $postings): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:80'],
            'trx_number' => ['required', 'string', 'max:50'],
            'entry_type' => ['required', Rule::in(['sale', 'reversal'])],
            'outlet_id' => ['required', 'integer', 'min:1'],
            'outlet_code' => ['required', 'string', 'max:20'],
            'shift_id' => ['required', 'integer', 'min:1'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::in(['cash', 'debit', 'qris'])],
            'net_sales_amount' => self::MONEY_RULE,
            'tax_amount' => self::MONEY_RULE,
            'total_amount' => self::MONEY_RULE,
        ]);
        foreach (['net_sales_amount', 'tax_amount', 'total_amount'] as $field) {
            $data[$field] = Money::of((string) $data[$field]);
        }

        [$posting, $created] = $postings->record($data);

        return ApiResponse::ok($posting, $created ? 201 : 200, ['duplicate' => ! $created]);
    }
}
