<?php

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Services\FCMService;

/** Gửi một push FCM chạy nền, tách worker riêng với RTDB để push chậm không chặn lệnh ghi. */
class FcmSendJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public function __construct(
        public readonly string $token,
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        FCMService::getInstance()->deliver($this->token, $this->payload);
    }
}
