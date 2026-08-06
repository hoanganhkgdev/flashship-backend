<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Kiểm tra Zalo token mỗi giờ, tự refresh khi còn dưới 30 phút
Schedule::command('zalo:refresh-token')->hourly();

// Dọn log lịch sử GPS tài xế cũ hơn 30 ngày, tránh phình bảng
Schedule::command('driver:prune-location-logs')->daily();

