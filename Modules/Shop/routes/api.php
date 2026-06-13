<?php

use Illuminate\Support\Facades\Route;
use Modules\Shop\Http\Controllers\AuthController;
use Modules\Shop\Http\Controllers\OrderController;
use Modules\Shop\Http\Controllers\PricingController;
use Modules\Shop\Http\Controllers\ShopAddressController;
use Modules\Shop\Http\Controllers\ShopNotificationController;
use Modules\Shop\Http\Controllers\VoucherController;

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
        Route::get('/pricing/estimate',       [PricingController::class, 'estimate']);
        Route::post('/pricing/estimate-batch', [PricingController::class, 'estimateBatch']);

        Route::prefix('addresses')->group(function () {
            Route::get('/',        [ShopAddressController::class, 'index']);
            Route::post('/',       [ShopAddressController::class, 'store']);
            Route::patch('/{id}',  [ShopAddressController::class, 'update']);
            Route::delete('/{id}', [ShopAddressController::class, 'destroy']);
        });

        Route::prefix('notifications')->group(function () {
            Route::get('/',              [ShopNotificationController::class, 'index']);
            Route::get('/unread-count',  [ShopNotificationController::class, 'unreadCount']);
            Route::post('/read-all',     [ShopNotificationController::class, 'markAllRead']);
            Route::post('/{id}/read',    [ShopNotificationController::class, 'markRead']);
            Route::delete('/{id}',       [ShopNotificationController::class, 'delete']);
        });

        Route::prefix('vouchers')->group(function () {
            Route::get('/',          [VoucherController::class, 'index']);
            Route::post('/validate', [VoucherController::class, 'validate']);
        });

        Route::prefix('orders')->group(function () {
            Route::get('/',                        [OrderController::class, 'index']);
            Route::get('/stats',                   [OrderController::class, 'stats']);
            Route::post('/',                       [OrderController::class, 'store']);
            Route::post('/batch',                  [OrderController::class, 'storeBatch']);
            Route::get('/{code}',                  [OrderController::class, 'show']);
            Route::post('/{code}/cancel',          [OrderController::class, 'cancel']);
            Route::post('/{code}/rate',            [OrderController::class, 'rate']);
            Route::post('/{code}/stops/{seq}/deliver', [OrderController::class, 'deliverStop']);
        });
    });
});
