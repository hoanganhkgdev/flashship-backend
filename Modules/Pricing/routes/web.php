<?php

// Trước đây có Route::resource('pricings', PricingController::class) — code
// scaffold mặc định của nwidart/laravel-modules, trỏ tới các method
// (index/create/store/show/edit/update/destroy) không hề tồn tại trên
// PricingController thật (chỉ có configs()/estimate()) — gọi vào sẽ lỗi 500,
// không dùng, đã xoá. CRUD bảng giá thật nằm ở panel Filament
// (PricingResource/ShopPricingResource) + Modules/Admin/routes/api.php.
