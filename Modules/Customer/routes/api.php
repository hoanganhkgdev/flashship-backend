<?php

use Illuminate\Support\Facades\Route;
use Modules\Customer\Http\Controllers\AuthController;
use Modules\Customer\Http\Controllers\OrderController;
use Modules\Pricing\Http\Controllers\PricingController;

Route::prefix('customer')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login',    [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me',      [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/pricing/estimate', [PricingController::class, 'estimate']);

        Route::prefix('orders')->group(function () {
            Route::get('/',              [OrderController::class, 'index']);
            Route::post('/',             [OrderController::class, 'store']);
            Route::get('/{code}',        [OrderController::class, 'show']);
            Route::post('/{code}/cancel',[OrderController::class, 'cancel']);
            Route::post('/{code}/rate',  [OrderController::class, 'rate']);
        });
    });
});
