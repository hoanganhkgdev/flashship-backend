<?php
namespace Modules\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Không còn sử dụng — hệ thống đã chuyển sang sequential dispatch.
 * Giữ lại để tránh lỗi nếu có job cũ còn trong queue.
 */
class ExpandBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $orderId, public readonly int $wave = 1) {}

    public function handle(): void
    {
        // no-op
    }
}
