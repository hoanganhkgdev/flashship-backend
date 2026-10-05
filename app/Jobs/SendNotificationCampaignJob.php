<?php

namespace App\Jobs;

use App\Models\NotificationCampaign;
use App\Services\NotificationCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNotificationCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Không tự thử lại: lỗi giữa chừng mà chạy lại sẽ gửi trùng thông báo cho khách.
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $campaignId) {}

    public function handle(NotificationCampaignService $service): void
    {
        if ($campaign = NotificationCampaign::find($this->campaignId)) {
            $service->deliver($campaign);
        }
    }
}
