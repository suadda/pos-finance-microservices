<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('logout', [AuthController::class, 'logout']);

    Route::middleware('jwt')->group(function () {
        Route::get('me', [AuthController::class, 'me']);

        Route::middleware('role:superadmin')->prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('{user}', [UserController::class, 'show'])->whereNumber('user');
            Route::match(['put', 'patch'], '{user}', [UserController::class, 'update'])->whereNumber('user');
            Route::delete('{user}', [UserController::class, 'deactivate'])->whereNumber('user');
            Route::post('{user}/activate', [UserController::class, 'activate'])->whereNumber('user');
        });
    });
});
