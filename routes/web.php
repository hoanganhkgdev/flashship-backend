<?php

use Illuminate\Support\Facades\Route;

// Vào tên miền gốc là tới trang quản trị: chưa đăng nhập thì Filament chuyển sang
// trang login, đã đăng nhập thì vào thẳng dashboard.
Route::redirect('/', '/admin');
// Đường dẫn cũ của trang Theo dõi phát đơn (trước khi đổi slug sang tiếng Việt)
Route::get('/admin/{tenant}/call-center-page', fn (string $tenant) => redirect(url('/admin/'.$tenant.'/tong-dai-dat-don').(request()->getQueryString() ? '?'.request()->getQueryString() : '')))->where('tenant', '[0-9]+');
Route::get('/admin/{tenant}/driver-map-page', fn (string $tenant) => redirect('/admin/'.$tenant.'/ban-do-tai-xe'))->where('tenant', '[0-9]+');
Route::get('/admin/{tenant}/dispatch-monitor-page', fn (string $tenant) => redirect('/admin/'.$tenant.'/theo-doi-phat-don'))->where('tenant', '[0-9]+');

// Trang pháp lý công khai (link trên cửa hàng ứng dụng): đọc từ nội dung quản trị đang dùng trong app
// để hai nơi luôn khớp nhau; nếu chưa có nội dung thì dùng bản tĩnh dự phòng.
foreach (\App\Services\LegalPageService::WEB as $path => $slug) {
    Route::get('/'.$path, function () use ($slug, $path) {
        $page = \Modules\Admin\Models\Page::where('slug', $slug)->where('is_active', true)->first();
        $view = $page && trim((string) $page->content) !== '' ? view('legal.page', ['page' => $page]) : view('legal.'.$path);

        return response($view)->header('Content-Type', 'text/html');
    });
}

Route::get('/support', function () {
    return response(view('legal.support'))->header('Content-Type', 'text/html');
});

Route::get('/ios', fn() => redirect('https://apps.apple.com/vn/app/flash-ship-%C4%91%E1%BA%B7t-%C4%91%C6%A1n/id6768362686'));
Route::get('/android', fn() => redirect('https://play.google.com/store/apps/details?id=vn.flashship.customer'));
Route::get('/download', fn() => view('download'));
