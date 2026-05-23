<?php

use Illuminate\Support\Facades\Route;
use Modules\Customer\Http\Controllers\AddressController;
use Modules\Customer\Http\Controllers\AuthController;
use Modules\Customer\Http\Controllers\OrderController;
use Modules\Customer\Http\Controllers\VoucherController;
use Modules\Pricing\Http\Controllers\PricingController;

Route::prefix('customer')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login',    [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me',              [AuthController::class, 'me']);
            Route::post('/logout',         [AuthController::class, 'logout']);
            Route::patch('/profile',       [AuthController::class, 'updateProfile']);
            Route::post('/avatar',         [AuthController::class, 'uploadAvatar']);
            Route::patch('/password',      [AuthController::class, 'changePassword']);
            Route::delete('/account',      [AuthController::class, 'deleteAccount']);
            Route::post('/fcm-token',      [AuthController::class, 'updateFcmToken']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/pricing/estimate', [PricingController::class, 'estimate']);
        Route::get('/vouchers',          [VoucherController::class, 'index']);
        Route::post('/vouchers/validate', [VoucherController::class, 'validate']);

        Route::prefix('addresses')->group(function () {
            Route::get('/',                      [AddressController::class, 'index']);
            Route::post('/',                     [AddressController::class, 'store']);
            Route::put('/{address}',             [AddressController::class, 'update']);
            Route::patch('/{address}/default',   [AddressController::class, 'setDefault']);
            Route::delete('/{address}',          [AddressController::class, 'destroy']);
        });

        Route::prefix('orders')->group(function () {
            Route::get('/',              [OrderController::class, 'index']);
            Route::post('/',             [OrderController::class, 'store']);
            Route::get('/{code}',        [OrderController::class, 'show']);
            Route::post('/{code}/cancel',[OrderController::class, 'cancel']);
            Route::post('/{code}/rate',  [OrderController::class, 'rate']);
        });
    });
});
