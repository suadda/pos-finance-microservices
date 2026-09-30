<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\InternalPostingController;
use App\Http\Controllers\PostingController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Service-to-service only (X-Service-Key). Blocked at the gateway.
Route::prefix('internal')->middleware('service.key')->group(function () {
    Route::post('postings', [InternalPostingController::class, 'store']);
});

Route::middleware(['jwt', 'role:staff_finance,manager_finance,superadmin'])->group(function () {
    Route::get('accounts', [AccountController::class, 'index']);

    Route::get('postings', [PostingController::class, 'index']);
    Route::get('postings/{posting}', [PostingController::class, 'show'])->whereNumber('posting');

    Route::get('reports/daily-sales', [ReportController::class, 'dailySales']);

    Route::post('reconciliations/run', [ReconciliationController::class, 'run']);
    Route::get('reconciliations', [ReconciliationController::class, 'index']);
    Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show'])->whereNumber('reconciliation');
    Route::patch('reconciliations/{reconciliation}/resolve', [ReconciliationController::class, 'resolve'])
        ->whereNumber('reconciliation')
        ->middleware('role:manager_finance,superadmin');
});
