<?php

namespace App\Http\Controllers;

use App\Models\EodReconciliation;
use App\Services\ReconciliationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReconciliationController
{
    public function __construct(private readonly ReconciliationService $service) {}

    /** GET /reconciliations — filters: outlet_id, status, date_from, date_to */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'outlet_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['pending', 'matched', 'mismatch', 'resolved'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $query = EodReconciliation::query()->orderByDesc('business_date')->orderBy('outlet_id');
        foreach (['outlet_id', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['date_from'])) {
            $query->whereDate('business_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('business_date', '<=', $filters['date_to']);
        }

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage()));
    }

    public function show(EodReconciliation $reconciliation): JsonResponse
    {
        return ApiResponse::ok($reconciliation);
    }

    /** POST /reconciliations/run {outlet_id, business_date} */
    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'integer', 'min:1'],
            'business_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $row = $this->service->run($request->attributes->get('auth_user'), (int) $data['outlet_id'], $data['business_date']);

        return ApiResponse::ok($row);
    }

    /** PATCH /reconciliations/{reconciliation}/resolve {resolution_note} */
    public function resolve(Request $request, EodReconciliation $reconciliation): JsonResponse
    {
        $data = $request->validate(['resolution_note' => ['required', 'string', 'min:5', 'max:2000']]);

        return ApiResponse::ok($this->service->resolve($request->attributes->get('auth_user'), $reconciliation, trim($data['resolution_note'])));
    }
}
