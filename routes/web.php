<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn() => view('welcome'));

Route::get('/privacy', function () {
    return response(view('legal.privacy'))->header('Content-Type', 'text/html');
});

Route::get('/terms', function () {
    return response(view('legal.terms'))->header('Content-Type', 'text/html');
});

Route::get('/support', function () {
    return response(view('legal.support'))->header('Content-Type', 'text/html');
});

Route::get('/ios', fn() => redirect('https://apps.apple.com/vn/app/flash-ship-%C4%91%E1%BA%B7t-%C4%91%C6%A1n/id6768362686'));
Route::get('/android', fn() => redirect('https://play.google.com/store/apps/details?id=vn.flashship.customer'));
Route::get('/download', function (\Illuminate\Http\Request $request) {
    $ua = strtolower($request->userAgent() ?? '');
    if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod')) {
        return redirect('https://apps.apple.com/vn/app/flash-ship-%C4%91%E1%BA%B7t-%C4%91%C6%A1n/id6768362686');
    }
    if (str_contains($ua, 'android')) {
        return redirect('https://play.google.com/store/apps/details?id=vn.flashship.customer');
    }
    return view('download');
});
