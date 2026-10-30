<?php

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Services\RTDBService;

/**
 * Một lệnh ghi RTDB chạy nền. Queue `rtdb` chỉ có MỘT worker nên các lệnh
 * được ghi đúng thứ tự gửi; việc thử lại nằm ngay trong job (không release
 * về cuối hàng) để lệnh cũ không bao giờ đè lên lệnh mới hơn.
 */
class RtdbWriteJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 1;

    // Phải nhỏ hơn retry_after (90s) của queue redis, nếu không job sẽ bị chạy 2 lần.
    public int $timeout = 80;

    public function __construct(
        public readonly string $op,
        public readonly string $path,
        public readonly mixed $value = null,
    ) {}

    public function handle(): void
    {
        RTDBService::performWrite($this->op, $this->path, $this->value);
    }
}
