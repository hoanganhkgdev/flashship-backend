<?php

use Illuminate\Support\Facades\Route;
use Modules\Shop\Http\Controllers\AuthController;
use Modules\Shop\Http\Controllers\OrderController;
use Modules\Shop\Http\Controllers\PricingController;
use Modules\Shop\Http\Controllers\ShopAddressController;
use Modules\Shop\Http\Controllers\ShopNotificationController;
use Modules\Shop\Http\Controllers\VoucherController;
use Modules\Shop\Http\Middleware\TrackShopEvent;

Route::prefix('shop')->middleware(TrackShopEvent::class)->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('/send-otp',            [AuthController::class, 'sendOtp']);
        Route::post('/verify-otp-register', [AuthController::class, 'verifyOtpAndRegister']);
        Route::post('/forgot-password',     [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password',      [AuthController::class, 'resetPassword']);
        Route::post('/login',               [AuthController::class, 'login']);

        // auth:sanctum không tự kiểm tra user_type — cùng lỗ hổng đã sửa cho
        // module Admin/Driver (2026-08-20), dùng lại middleware user_type.
        Route::middleware(['auth:sanctum', 'user_type:shop'])->group(function () {
            Route::get('/me',           [AuthController::class, 'me']);
            Route::post('/logout',      [AuthController::class, 'logout']);
            Route::patch('/profile',    [AuthController::class, 'updateProfile']);
            Route::post('/avatar',      [AuthController::class, 'uploadAvatar']);
            Route::patch('/password',   [AuthController::class, 'changePassword']);
            Route::post('/fcm-token',   [AuthController::class, 'updateFcmToken']);
            Route::delete('/account',   [AuthController::class, 'deleteAccount']);

            Route::post('/change-phone/send-otp', [AuthController::class, 'changePhoneSendOtp']);
            Route::post('/change-phone/verify',   [AuthController::class, 'changePhoneVerify']);

            Route::get('/devices',                [AuthController::class, 'devices']);
            Route::delete('/devices/{id}',         [AuthController::class, 'revokeDevice']);
            Route::post('/devices/revoke-others',  [AuthController::class, 'revokeOtherDevices']);
        });
    });

    Route::middleware(['auth:sanctum', 'user_type:shop'])->group(function () {
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
