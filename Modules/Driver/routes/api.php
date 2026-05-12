<?php

use Illuminate\Support\Facades\Route;
use Modules\Driver\Http\Controllers\DriverController;
use Modules\Driver\Http\Controllers\EarningController;
use Modules\Driver\Http\Controllers\WalletController;
use Modules\Driver\Http\Controllers\DebtController;
use Modules\Driver\Http\Controllers\OrderController;

Route::middleware('auth:sanctum')->group(function () {

    Route::prefix('driver')->group(function () {
        Route::get('/profile',                       [DriverController::class, 'profile']);
        Route::post('/profile/update',               [DriverController::class, 'updateProfile']);
        Route::post('/change-password',              [DriverController::class, 'changePassword']);
        Route::post('/update-fcm-token',             [DriverController::class, 'updateFcmToken']);
        Route::post('/toggle-status',                [DriverController::class, 'toggleOnline']);
        Route::post('/update-location',              [DriverController::class, 'updateLocation']);
        Route::get('/stats',                         [DriverController::class, 'stats']);
        Route::get('/hotspots',                      [DriverController::class, 'hotspots']);
        Route::get('/announcements',                 [DriverController::class, 'announcements']);
        Route::get('/notifications',                 [DriverController::class, 'notifications']);
        Route::post('/notifications/mark-read/{id}', [DriverController::class, 'markNotificationAsRead']);
        Route::post('/delete-account/request',       [DriverController::class, 'requestDeleteAccount']);
        Route::post('/delete-account/cancel',        [DriverController::class, 'cancelDeleteAccount']);
    });

    Route::prefix('orders')->group(function () {
        Route::get('/pending-offer',     [OrderController::class, 'pendingOffer']);
        Route::get('/my-orders',         [OrderController::class, 'myOrders']);
        Route::get('/completed',         [OrderController::class, 'completedOrders']);
        Route::get('/dashboard',         [OrderController::class, 'dashboard']);
        Route::get('/recent',            [EarningController::class, 'recentOrders']);
        Route::post('/{order}/view-offer', [OrderController::class, 'viewOffer']);
        Route::post('/{order}/accept',        [OrderController::class, 'accept']);
        Route::post('/{order}/decline',       [OrderController::class, 'decline']);
        Route::post('/{order}/update-status', [OrderController::class, 'updateStatus']);
        Route::post('/{order}/complete',      [OrderController::class, 'complete']);
    });

    Route::prefix('earnings')->group(function () {
        Route::get('/weekly',  [EarningController::class, 'weekly']);
        Route::get('/monthly', [EarningController::class, 'monthly']);
        Route::get('/kpi',     [EarningController::class, 'kpi']);
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
        Route::post('/{id}/pay/wallet', [DebtController::class, 'payWithWallet']);
    });
});
