<?php

namespace Modules\Order\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;

/**
 * Nạp hiệu suất gần đây cho cả lô ứng viên bằng đúng hai truy vấn.
 *
 * Chỉ những offer mà tài xế thật sự có quyền quyết định mới đi vào mẫu:
 * accepted, declined, hoặc timeout sau khi thiết bị đã nhận offer. Offer bị
 * đóng sớm vì người khác trong wave nhận trước / tài xế vừa bận / lỗi RTDB
 * đều không được xem là lỗi của tài xế.
 */
final class DispatchPerformanceLoader
{
    public const OFFER_WINDOW_DAYS = 14;

    public const RATING_WINDOW_DAYS = 90;

    public function hydrate(Collection $drivers): Collection
    {
        $driverIds = $drivers->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($driverIds === []) {
            return $drivers;
        }

        $offerStats = DB::table('order_dispatch_logs')
            ->whereIn('driver_id', $driverIds)
            ->where('offered_at', '>=', now()->subDays(self::OFFER_WINDOW_DAYS))
            ->groupBy('driver_id')
            ->selectRaw("driver_id,
                SUM(CASE WHEN result = 'accepted' THEN 1 ELSE 0 END) AS accepted_count,
                SUM(CASE
                    WHEN result IN ('accepted', 'declined') THEN 1
                    WHEN result = 'expired'
                        AND received_at IS NOT NULL
                        AND expires_at IS NOT NULL
                        AND responded_at >= expires_at THEN 1
                    ELSE 0
                END) AS actionable_count")
            ->get()
            ->keyBy('driver_id');

        $ratingStats = DB::table('orders')
            ->whereIn('delivery_man_id', $driverIds)
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(self::RATING_WINDOW_DAYS))
            ->whereNotNull('driver_rating')
            ->where('rating_hidden', false)
            ->groupBy('delivery_man_id')
            ->selectRaw('delivery_man_id, COUNT(*) AS rating_count, SUM(driver_rating) AS rating_sum')
            ->get()
            ->keyBy('delivery_man_id');

        return $drivers->each(function (User $driver) use ($offerStats, $ratingStats) {
            $offers = $offerStats->get($driver->id);
            $ratings = $ratingStats->get($driver->id);

            $driver->setAttribute('_dispatch_accepted_count', (int) ($offers->accepted_count ?? 0));
            $driver->setAttribute('_dispatch_actionable_count', (int) ($offers->actionable_count ?? 0));
            $driver->setAttribute('_dispatch_rating_count', (int) ($ratings->rating_count ?? 0));
            $driver->setAttribute('_dispatch_rating_sum', (float) ($ratings->rating_sum ?? 0));
        });
    }
}
