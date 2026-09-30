<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AccountController
{
    /** GET /accounts — chart of accounts. */
    public function index(): JsonResponse
    {
        return ApiResponse::ok(Account::orderBy('code')->get());
    }
}
