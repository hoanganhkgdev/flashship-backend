<?php

namespace Modules\Order\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderHistory;

/**
 * Dòng thời gian của đơn: ghi các sự kiện hành động (hủy, gán tay, sửa, lấy hàng, hoàn thành...) vào
 * order_histories, và ghép với dữ liệu đã có (tạo đơn, nhật ký offer, hoàn thành, đánh giá) khi đọc.
 */
class OrderTimeline
{
    /** Lý do hủy đơn. Khóa 'admin' là dữ liệu cũ (hủy không ghi lý do). */
    public const CANCEL_REASONS = [
        'customer_cancel' => 'Khách hủy',
        'wrong_order' => 'Đặt nhầm',
        'duplicate' => 'Đặt trùng',
        'admin_no_driver' => 'Không có tài xế',
        'driver_no_show' => 'Tài xế không đến',
        'wrong_address' => 'Sai địa chỉ',
        'other' => 'Lý do khác',
    ];

    public static function cancelReasonLabel(?string $reason): string
    {
        return match (true) {
            $reason === null || $reason === '' => 'Không rõ lý do',
            $reason === 'admin' => 'Admin hủy (chưa ghi lý do)',
            $reason === 'no_driver' => 'Hệ thống: hết thời gian tìm tài xế',
            $reason === 'customer' => 'Khách/cửa hàng hủy trong app',
            default => self::CANCEL_REASONS[$reason] ?? $reason,
        };
    }

    /** Ghi một sự kiện; không bao giờ làm hỏng thao tác chính nếu ghi lỗi. */
    public static function record(Order|int $order, string $type, string $description, ?int $userId = null, array $meta = []): void
    {
        try {
            OrderHistory::create([
                'order_id' => $order instanceof Order ? $order->id : $order,
                'user_id' => $userId,
                'type' => $type,
                'description' => mb_substr($description, 0, 250),
                'metadata' => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[OrderTimeline] record failed', ['order' => $order instanceof Order ? $order->id : $order, 'type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /** @return Collection<int, array{at:\Carbon\Carbon,type:string,text:string,who:?string}> theo thứ tự thời gian */
    public static function for(Order $order): Collection
    {
        $names = fn (array $ids) => DB::table('users')->whereIn('id', array_filter(array_unique($ids)))->pluck('name', 'id');
        $logs = DB::table('order_dispatch_logs')->where('order_id', $order->id)->orderBy('id')->get();
        $histories = OrderHistory::where('order_id', $order->id)->orderBy('id')->get();
        $users = $names(array_merge(
            [$order->created_by, $order->delivery_man_id, $order->cancelled_by],
            $logs->pluck('driver_id')->all(),
            $histories->pluck('user_id')->all(),
        ));

        $events = collect();
        $events->push(['at' => $order->created_at, 'type' => 'created', 'text' => 'Tạo đơn'.($order->platform ? ' ('.self::platform($order->platform).')' : ''), 'who' => $users[$order->created_by] ?? ($order->sender_name ?: null)]);

        if ($order->dispatch_started_at) {
            $events->push(['at' => $order->dispatch_started_at, 'type' => 'dispatch', 'text' => 'Bắt đầu tìm tài xế', 'who' => null]);
        }

        foreach ($logs as $l) {
            $driver = $users[$l->driver_id] ?? ('Tài xế #'.$l->driver_id);
            if ($l->offered_at) {
                $events->push(['at' => \Carbon\Carbon::parse($l->offered_at), 'type' => 'offer', 'text' => "Gửi đơn cho {$driver}", 'who' => null]);
            }
            if ($l->responded_at && $l->result !== 'pending') {
                $verb = ['accepted' => 'nhận đơn', 'declined' => 'từ chối đơn', 'expired' => 'không phản hồi, đơn chuyển người khác'][$l->result] ?? $l->result;
                $events->push(['at' => \Carbon\Carbon::parse($l->responded_at), 'type' => 'offer_'.$l->result, 'text' => "{$driver} {$verb}", 'who' => null]);
            }
        }

        foreach ($histories as $h) {
            $events->push(['at' => $h->created_at, 'type' => $h->type, 'text' => $h->description, 'who' => $h->user_id ? ($users[$h->user_id] ?? 'Quản trị viên') : null]);
        }

        $types = $events->pluck('type');
        if ($order->completed_at && ! $types->contains('completed')) {
            $events->push(['at' => $order->completed_at, 'type' => 'completed', 'text' => 'Hoàn thành đơn', 'who' => $users[$order->delivery_man_id] ?? null]);
        }
        if ($order->status === 'cancelled' && ! $types->contains('cancelled')) {
            $events->push(['at' => $order->cancelled_at ?? $order->updated_at, 'type' => 'cancelled', 'text' => 'Đơn bị hủy — '.self::cancelReasonLabel($order->cancel_reason), 'who' => $users[$order->cancelled_by] ?? null]);
        }
        if ($order->rated_at && $order->driver_rating) {
            $events->push(['at' => $order->rated_at, 'type' => 'rated', 'text' => 'Khách đánh giá '.$order->driver_rating.' sao', 'who' => null]);
        }

        return $events->sortBy(fn ($e) => $e['at']?->getTimestamp() ?? 0)->values();
    }

    private static function platform(string $p): string
    {
        return ['customer_app' => 'app khách', 'shop_app' => 'app cửa hàng', 'call_center' => 'tổng đài'][$p] ?? $p;
    }
}
