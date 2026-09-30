<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Services\ShiftService;
use App\Support\AccessScope;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController
{
    private const MONEY_RULE = ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'];

    public function __construct(private readonly ShiftService $shifts) {}

    /** POST /shifts/open {opening_cash, outlet_id? (superadmin only)} */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_cash' => self::MONEY_RULE,
            'outlet_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $shift = $this->shifts->open($request->attributes->get('auth_user'), Money::of($data['opening_cash']), $data['outlet_id'] ?? null);

        return ApiResponse::created($shift->load('outlet'));
    }

    /** GET /shifts/current — data is null when the cashier has no open shift. */
    public function current(Request $request): JsonResponse
    {
        $shift = $this->shifts->current($request->attributes->get('auth_user'));

        return ApiResponse::ok($shift ? $this->shifts->summary($shift->load('outlet')) : null);
    }

    /** GET /shifts/{shift}/summary */
    public function summary(Request $request, Shift $shift): JsonResponse
    {
        AccessScope::ensureCanSee($request->attributes->get('auth_user'), $shift);

        return ApiResponse::ok($this->shifts->summary($shift->load('outlet')));
    }

    /** POST /shifts/{shift}/close {actual_cash} */
    public function close(Request $request, Shift $shift): JsonResponse
    {
        $data = $request->validate(['actual_cash' => self::MONEY_RULE]);

        return ApiResponse::ok($this->shifts->close($request->attributes->get('auth_user'), $shift, Money::of($data['actual_cash'])));
    }
}
