<?php

use Illuminate\Support\Facades\Route;
use Modules\Driver\Http\Controllers\DriverController;
use Modules\Driver\Http\Controllers\EarningController;
use Modules\Driver\Http\Controllers\WalletController;
use Modules\Driver\Http\Controllers\DebtController;
use Modules\Driver\Http\Controllers\OrderController;
use Modules\Driver\Http\Controllers\ScoreController;
use Modules\Driver\Http\Controllers\PaymentController;
use Modules\Driver\Http\Controllers\SupportController;
// Webhook PayOS — public, không cần auth
Route::post('/payment/webhook/payos', [PaymentController::class, 'webhook']);

Route::middleware(['auth:sanctum', 'driver.active'])->group(function () {

    Route::prefix('driver')->group(function () {
        Route::get('/profile',                       [DriverController::class, 'profile']);
        Route::post('/profile/update',               [DriverController::class, 'updateProfile']);
        Route::post('/change-password/send-otp',     [DriverController::class, 'sendChangePasswordOtp']);
        Route::post('/change-password',              [DriverController::class, 'changePassword']);
        Route::post('/update-fcm-token',             [DriverController::class, 'updateFcmToken']);
        Route::post('/toggle-status',                [DriverController::class, 'toggleOnline']);
        Route::post('/update-location',              [DriverController::class, 'updateLocation']);
        Route::get('/stats',                         [DriverController::class, 'stats']);
        Route::post('/profile/bank',                 [DriverController::class, 'updateBank']);
        Route::post('/profile/license',              [DriverController::class, 'uploadLicense']);
        Route::post('/profile/cccd-image',           [DriverController::class, 'uploadCccdImage']);
        Route::get('/bank-lists',                   [DriverController::class, 'bankLists']);
        Route::get('/shifts',                        [DriverController::class, 'shifts']);
        Route::post('/shifts/select',                 [DriverController::class, 'selectShift']);
        Route::post('/shifts/change-request',          [DriverController::class, 'submitShiftChangeRequest']);
        Route::get('/shifts/change-request/status',   [DriverController::class, 'shiftChangeRequestStatus']);
        Route::get('/cities',                        [DriverController::class, 'cities']);
        Route::post('/delete-account/request',       [DriverController::class, 'requestDeleteAccount']);
        Route::post('/delete-account/cancel',        [DriverController::class, 'cancelDeleteAccount']);
        Route::get('/support',                       [SupportController::class, 'index']);
    });

    Route::prefix('orders')->group(function () {
        Route::get('/pending-offer',          [OrderController::class, 'pendingOffer']);
        Route::get('/my-orders',              [OrderController::class, 'myOrders']);
        Route::get('/completed',              [OrderController::class, 'completedOrders']);
        Route::get('/dashboard',              [OrderController::class, 'dashboard']);
        Route::get('/recent',                 [EarningController::class, 'recentOrders']);
        Route::post('/{order}/view-offer',    [OrderController::class, 'viewOffer']);
        Route::post('/{order}/accept',        [OrderController::class, 'accept']);
        Route::post('/{order}/decline',       [OrderController::class, 'decline']);
        Route::post('/{order}/update-status',        [OrderController::class, 'updateStatus']);
        Route::post('/{order}/complete',             [OrderController::class, 'complete']);
        Route::post('/{order}/stops/{seq}/deliver',  [OrderController::class, 'deliverStop']);
    });

    Route::prefix('earnings')->group(function () {
        Route::get('/weekly',  [EarningController::class, 'weekly']);
        Route::get('/monthly', [EarningController::class, 'monthly']);
        Route::get('/summary', [EarningController::class, 'summary']);
    });

    Route::prefix('wallet')->group(function () {
        Route::get('/',                  [WalletController::class, 'index']);
        Route::get('/transactions',      [WalletController::class, 'transactions']);
        Route::post('/withdraw',         [WalletController::class, 'withdraw']);
        Route::get('/withdraw/requests', [WalletController::class, 'withdrawRequests']);
    });

    Route::prefix('debts')->group(function () {
        Route::get('/',                 [DebtController::class, 'index']);
        Route::get('/{id}',             [DebtController::class, 'show']);
    });

    Route::prefix('driver/payment')->group(function () {
        Route::post('/create',          [PaymentController::class, 'create']);
        Route::get('/{orderCode}/status', [PaymentController::class, 'status']);
    });

    Route::prefix('driver/score')->group(function () {
        Route::get('',         [ScoreController::class, 'index']);
        Route::get('/history', [ScoreController::class, 'history']);
    });
});
