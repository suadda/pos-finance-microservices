<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class OutletController
{
    /** GET /outlets — active outlets, readable by every role. */
    public function index(): JsonResponse
    {
        return ApiResponse::ok(Outlet::where('is_active', true)->orderBy('id')->get());
    }
}
