<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\AuthController;
use Modules\Core\Models\City;
use Modules\Core\Models\Shift;
use Modules\Admin\Models\Banner;
use Modules\Admin\Models\Page;
use Modules\Admin\Models\SupportConfig;

/*
|----------------------------------------------------------------------
| Driver Auth
|----------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/login',    [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me',      [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

/*
|----------------------------------------------------------------------
| Public endpoints
|----------------------------------------------------------------------
*/
Route::get('/cities', fn() => response()->json(['success' => true, 'data' => City::where('is_active', true)->get()]));

Route::get('/app-version', fn() => response()->json(['version' => config('app.version', '1.0.0')]));

Route::get('/banners', function () {
    return response()->json(['success' => true, 'data' => \Modules\Admin\Models\Banner::where('is_active', true)->orderBy('sort_order')->get()]);
});

Route::get('/support-configs', function () {
    return response()->json(['success' => true, 'data' => \Modules\Admin\Models\SupportConfig::where('is_active', true)->orderBy('priority')->get()]);
});

Route::get('/pages/{slug}', function (string $slug) {
    $page = \Modules\Admin\Models\Page::where('slug', $slug)->where('is_active', true)->first();
    if (!$page) return response()->json(['success' => false, 'message' => 'Không tìm thấy trang'], 404);
    return response()->json(['success' => true, 'data' => $page]);
});
