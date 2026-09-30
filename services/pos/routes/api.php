<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InternalController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

// Service-to-service only (X-Service-Key). Blocked at the gateway.
Route::prefix('internal')->middleware('service.key')->group(function () {
    Route::get('eod-summary', [InternalController::class, 'eodSummary']);
});

Route::middleware('jwt')->group(function () {
    Route::get('outlets', [OutletController::class, 'index']);

    // Products: readable by every POS role, writable by supervisor_pos / superadmin.
    Route::middleware('role:kasir,supervisor_pos,superadmin')->group(function () {
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product');
    });
    Route::middleware('role:supervisor_pos,superadmin')->group(function () {
        Route::post('products', [ProductController::class, 'store']);
        Route::match(['put', 'patch'], 'products/{product}', [ProductController::class, 'update'])->whereNumber('product');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product');
    });

    // Cashier operations.
    Route::middleware('role:kasir,superadmin')->group(function () {
        Route::post('shifts/open', [ShiftController::class, 'open']);
        Route::get('shifts/current', [ShiftController::class, 'current']);
        Route::post('shifts/{shift}/close', [ShiftController::class, 'close'])->whereNumber('shift');
        Route::post('transactions', [TransactionController::class, 'store']);
        Route::post('transactions/{transaction}/pay', [TransactionController::class, 'pay'])->whereNumber('transaction');
    });

    Route::middleware('role:kasir,supervisor_pos,superadmin')->group(function () {
        Route::get('dashboard', DashboardController::class);
        Route::get('shifts/{shift}/summary', [ShiftController::class, 'summary'])->whereNumber('shift');
        Route::get('transactions', [TransactionController::class, 'index']);
        Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->whereNumber('transaction');
        Route::post('transactions/{transaction}/resync-finance', [TransactionController::class, 'resync'])->whereNumber('transaction');
    });

    Route::post('transactions/{transaction}/void', [TransactionController::class, 'void'])
        ->whereNumber('transaction')
        ->middleware('role:supervisor_pos,superadmin');
});
