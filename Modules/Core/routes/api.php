<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\AuthController;
use Modules\Core\Models\City;
use Modules\Core\Models\ServiceType;
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
    Route::post('/login',                 [AuthController::class, 'login']);
    Route::post('/register',              [AuthController::class, 'register']);
    Route::post('/send-otp',              [AuthController::class, 'sendOtp']);
    Route::post('/verify-otp-register',   [AuthController::class, 'verifyOtpAndRegister']);

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

Route::get('/cities/nearest', function (\Illuminate\Http\Request $request) {
    $lat = (float) $request->query('lat', 0);
    $lng = (float) $request->query('lng', 0);

    $cities = City::where('is_active', true)
        ->whereNotNull('lat')->whereNotNull('lng')
        ->get(['id', 'name', 'lat', 'lng']);

    if ($cities->isEmpty()) {
        $city = City::where('is_active', true)->first(['id', 'name']);
        return response()->json(['success' => true, 'data' => $city]);
    }

    $nearest  = null;
    $minDist  = PHP_FLOAT_MAX;
    foreach ($cities as $city) {
        $dLat = deg2rad((float)$city->lat - $lat);
        $dLng = deg2rad((float)$city->lng - $lng);
        $d    = $dLat * $dLat + $dLng * $dLng;
        if ($d < $minDist) { $minDist = $d; $nearest = $city; }
    }

    return response()->json(['success' => true, 'data' => $nearest ? ['id' => $nearest->id, 'name' => $nearest->name] : null]);
});

Route::get('/service-types', function () {
    $items = ServiceType::active()->get()->map(function ($s) {
        $data = $s->toArray();
        $data['icon_url'] = $s->icon_url
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($s->icon_url)
            : null;
        return $data;
    });
    return response()->json(['success' => true, 'data' => $items]);
});

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
