<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Kiểm tra Zalo token mỗi giờ, tự refresh khi còn dưới 30 phút
Schedule::command('zalo:refresh-token')->hourly();

// Sync vị trí tài xế từ Firebase RTDB → Redis GEO mỗi 30 giây
Schedule::command('driver:sync-geo')->everyThirtySeconds();
