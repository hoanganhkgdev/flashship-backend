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
    Route::post('/send-otp',              [AuthController::class, 'sendOtp']);
    Route::post('/verify-otp-register',   [AuthController::class, 'verifyOtpAndRegister']);
    Route::post('/forgot-password',       [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',        [AuthController::class, 'resetPassword']);

    // auth:sanctum + driver.active KHÔNG kiểm tra user_type — customer/shop
    // đăng nhập vẫn gọi được /auth/firebase-token bằng token của họ, lấy ra
    // 1 Firebase custom auth token hợp lệ với UID "driver_{id_của_họ}_..."
    // — nếu id đó trùng với 1 driver thật (cùng bảng users, id share chung
    // dải số), Firebase Security Rules (cho phép UID driver_{id} ghi vào
    // /locations/driver_{id}) có thể bị lợi dụng để ghi đè GPS của driver
    // thật. Cùng lỗ hổng hệ thống đã sửa cho 4 module khác (2026-08-20).
    Route::middleware(['auth:sanctum', 'driver.active', 'user_type:driver'])->group(function () {
        Route::get('/me',             [AuthController::class, 'me']);
        Route::post('/logout',        [AuthController::class, 'logout']);
        Route::post('/firebase-token', [AuthController::class, 'firebaseToken']);
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

Route::get('/app-version', function (\Illuminate\Http\Request $request) {
    $platform = in_array($request->query('platform'), ['customer', 'driver', 'shop'])
        ? $request->query('platform')
        : 'customer';
    $s = \App\Models\AppVersionSetting::forPlatform($platform);
    return response()->json([
        'min_version'             => $s->min_version,
        'latest_version'          => $s->latest_version,
        'android_min_version'     => $s->android_min_version    ?? $s->min_version,
        'android_latest_version'  => $s->android_latest_version ?? $s->latest_version,
        'ios_min_version'         => $s->ios_min_version        ?? $s->min_version,
        'ios_latest_version'      => $s->ios_latest_version     ?? $s->latest_version,
        'android_url'             => $s->android_url,
        'ios_url'                 => $s->ios_url,
        'force_update'            => $s->force_update,
        'force_message'           => $s->force_message,
    ]);
});

Route::get('/banners', function (\Illuminate\Http\Request $request) {
    $cityId = $request->query('city_id');

    $banners = \Modules\Admin\Models\Banner::where('is_active', true)
        ->when($cityId, fn ($q) => $q->where(fn ($q2) => $q2->where('city_id', $cityId)->orWhereNull('city_id')))
        ->orderBy('sort_order')
        ->get();

    return response()->json(['success' => true, 'data' => $banners]);
});

Route::get('/support-configs', function () {
    return response()->json(['success' => true, 'data' => \Modules\Admin\Models\SupportConfig::where('is_active', true)->orderBy('priority')->get()]);
});

Route::get('/pages/{slug}', function (string $slug) {
    $page = \Modules\Admin\Models\Page::where('slug', $slug)->where('is_active', true)->first();
    if (!$page) return response()->json(['success' => false, 'message' => 'Không tìm thấy trang'], 404);
    return response()->json(['success' => true, 'data' => $page]);
});
