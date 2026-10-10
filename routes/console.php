<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Order\Services\DispatchExpirySweeper;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Kiểm tra Zalo token mỗi giờ, tự refresh khi còn dưới 30 phút
Schedule::command('zalo:refresh-token')->hourly();

// Dọn log lịch sử GPS tài xế cũ hơn 30 ngày, tránh phình bảng
Schedule::command('driver:prune-location-logs')->daily();


// Chuyển các chiến dịch thông báo hẹn giờ đã tới hạn sang hàng đợi gửi
Schedule::call(fn () => app(\App\Services\NotificationCampaignService::class)->dispatchDue())
    ->everyMinute()
    ->name('notification-campaigns-dispatch-due')
    ->withoutOverlapping();

// Deadline phát đơn cần chính xác tới giây; delayed job trên queue chung vẫn
// được giữ làm fallback, còn sweeper này xử lý đúng hạn khi queue đang bận.
Schedule::call(fn () => app(DispatchExpirySweeper::class)->sweep())
    ->everySecond()
    ->name('dispatch-expire-offers');
