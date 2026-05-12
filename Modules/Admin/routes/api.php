<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminController;
use Modules\Admin\Http\Controllers\PricingAdminController;
use Modules\Admin\Http\Controllers\WalletAdminController;

Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me',                   [AdminController::class, 'me']);
        Route::get('/orders',               [AdminController::class, 'orders']);
        Route::get('/orders/{id}',          [AdminController::class, 'showOrder']);
        Route::post('/orders/{id}/assign',  [AdminController::class, 'assignDriver']);
        Route::get('/drivers',              [AdminController::class, 'drivers']);

        // Bảng giá
        Route::get('/pricing',                              [PricingAdminController::class, 'index']);
        Route::get('/pricing/preview',                      [PricingAdminController::class, 'preview']);
        Route::get('/pricing/{serviceType}',                [PricingAdminController::class, 'show']);
        Route::put('/pricing/{serviceType}',                [PricingAdminController::class, 'update']);
        Route::patch('/pricing/{serviceType}/toggle',       [PricingAdminController::class, 'toggle']);

        // Bằng lái ô tô
        Route::get('/car-licenses',                          [PricingAdminController::class, 'carLicenses']);
        Route::patch('/drivers/{driverId}/car-license',      [PricingAdminController::class, 'approveCarLicense']);

        // Quản lý ví tài xế
        Route::prefix('wallet')->group(function () {
            Route::get('/',                                  [WalletAdminController::class, 'overview']);
            Route::get('/withdraws',                         [WalletAdminController::class, 'withdrawRequests']);
            Route::post('/withdraws/{id}/approve',           [WalletAdminController::class, 'approveWithdraw']);
            Route::post('/withdraws/{id}/reject',            [WalletAdminController::class, 'rejectWithdraw']);
            Route::get('/drivers/{driverId}',                [WalletAdminController::class, 'driverWallet']);
            Route::get('/drivers/{driverId}/transactions',   [WalletAdminController::class, 'driverTransactions']);
            Route::post('/drivers/{driverId}/adjust',        [WalletAdminController::class, 'adjustWallet']);
        });
    });
});
