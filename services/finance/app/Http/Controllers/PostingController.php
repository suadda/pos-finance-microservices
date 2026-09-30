<?php

namespace App\Http\Controllers;

use App\Models\Posting;
use App\Services\JournalBuilder;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostingController
{
    /** GET /postings — filters: outlet_id, business_date, date_from, date_to, payment_method, entry_type, search */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'outlet_id' => ['nullable', 'integer'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'payment_method' => ['nullable', Rule::in(['cash', 'debit', 'qris'])],
            'entry_type' => ['nullable', Rule::in(['sale', 'reversal'])],
            'search' => ['nullable', 'string', 'max:50'],
        ]);

        $query = Posting::query()->latest('id');
        foreach (['outlet_id', 'payment_method', 'entry_type'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['business_date'])) {
            $query->whereDate('business_date', $filters['business_date']);
        }
        if (isset($filters['date_from'])) {
            $query->whereDate('business_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('business_date', '<=', $filters['date_to']);
        }
        if (isset($filters['search'])) {
            $query->where('trx_number', 'like', "%{$filters['search']}%");
        }

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage()));
    }

    /** GET /postings/{posting} — with journal lines, account names and a balance check. */
    public function show(Posting $posting): JsonResponse
    {
        $posting->load('lines.account');
        [$debit, $credit] = JournalBuilder::totals($posting->lines);

        return ApiResponse::ok([
            ...$posting->toArray(),
            'total_debit' => $debit,
            'total_credit' => $credit,
            'is_balanced' => JournalBuilder::isBalanced($posting->lines),
        ]);
    }
}
