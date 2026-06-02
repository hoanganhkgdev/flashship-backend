<?php

use Illuminate\Support\Facades\Route;
use Modules\Pricing\Http\Controllers\PricingController;
use Modules\Shop\Http\Controllers\AuthController;
use Modules\Shop\Http\Controllers\OrderController;

Route::prefix('shop')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('/send-otp',            [AuthController::class, 'sendOtp']);
        Route::post('/verify-otp-register', [AuthController::class, 'verifyOtpAndRegister']);
        Route::post('/login',               [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me',           [AuthController::class, 'me']);
            Route::post('/logout',      [AuthController::class, 'logout']);
            Route::patch('/profile',    [AuthController::class, 'updateProfile']);
            Route::post('/avatar',      [AuthController::class, 'uploadAvatar']);
            Route::patch('/password',   [AuthController::class, 'changePassword']);
            Route::post('/fcm-token',   [AuthController::class, 'updateFcmToken']);
            Route::delete('/account',   [AuthController::class, 'deleteAccount']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/pricing/estimate', [PricingController::class, 'estimate']);

        Route::prefix('orders')->group(function () {
            Route::get('/',               [OrderController::class, 'index']);
            Route::post('/',              [OrderController::class, 'store']);
            Route::get('/{code}',         [OrderController::class, 'show']);
            Route::post('/{code}/cancel', [OrderController::class, 'cancel']);
            Route::post('/{code}/rate',   [OrderController::class, 'rate']);
        });
    });
});
