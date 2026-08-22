<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminController;
use Modules\Admin\Http\Controllers\PricingAdminController;
use Modules\Admin\Http\Controllers\ZaloTokenController;

Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminController::class, 'login']);

    // auth:sanctum chỉ xác thực token hợp lệ — KHÔNG tự chặn được 1 tài
    // khoản driver/customer/shop đã đăng nhập gọi API admin bằng chính
    // token của họ. user_type:admin,subadmin mới là chặn thật (đồng bộ
    // đúng 2 loại được phép per AdminController::login()).
    Route::middleware(['auth:sanctum', 'user_type:admin,subadmin'])->group(function () {
        Route::get('/me',                   [AdminController::class, 'me']);
        Route::get('/orders',               [AdminController::class, 'orders']);
        Route::get('/orders/{id}',          [AdminController::class, 'showOrder']);
        Route::post('/orders/{id}/assign',  [AdminController::class, 'assignDriver']);
        Route::get('/drivers',              [AdminController::class, 'drivers']);

        // Bảng giá — xem thì admin+subadmin đều được, nhưng SỬA/BẬT-TẮT chỉ
        // admin-full (đồng bộ đúng giới hạn đã áp cho PricingResource/
        // ShopPricingResource ở panel Filament — subadmin phá giá toàn hệ
        // thống qua API này trước đây bị bỏ sót, không đồng bộ với fix đó).
        Route::get('/pricing',                              [PricingAdminController::class, 'index']);
        Route::get('/pricing/preview',                      [PricingAdminController::class, 'preview']);
        Route::get('/pricing/{serviceType}',                [PricingAdminController::class, 'show']);
        Route::middleware('user_type:admin')->group(function () {
            Route::put('/pricing/{serviceType}',            [PricingAdminController::class, 'update']);
            Route::patch('/pricing/{serviceType}/toggle',   [PricingAdminController::class, 'toggle']);
        });

        // Bằng lái ô tô
        Route::get('/car-licenses',                          [PricingAdminController::class, 'carLicenses']);
        Route::patch('/drivers/{driverId}/car-license',      [PricingAdminController::class, 'approveCarLicense']);

        // Zalo ZNS token
        Route::get('/zalo-token',          [ZaloTokenController::class, 'show']);
        Route::post('/zalo-token',         [ZaloTokenController::class, 'update']);
        Route::post('/zalo-token/refresh', [ZaloTokenController::class, 'refresh']);

    });
});
