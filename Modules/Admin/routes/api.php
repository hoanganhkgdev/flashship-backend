<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminController;

Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me',                   [AdminController::class, 'me']);
        Route::get('/orders',               [AdminController::class, 'orders']);
        Route::get('/orders/{id}',          [AdminController::class, 'showOrder']);
        Route::post('/orders/{id}/assign',  [AdminController::class, 'assignDriver']);
        Route::get('/drivers',              [AdminController::class, 'drivers']);
    });
});
