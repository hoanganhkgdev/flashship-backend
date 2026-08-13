<?php
namespace Modules\Order\Services;

use App\Events\DispatchStateChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\RTDBService;
use Modules\Customer\Http\Controllers\CustomerNotificationController;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;

class DispatchManualAssignment
{
    /**
     * Tổng đài gán CỨNG 1 tài xế cụ thể cho đơn — KHÔNG qua bước offer/chờ
     * xác nhận trong app, đơn chuyển thẳng sang "assigned" (vào mục "Đã
     * nhận" của tài xế ngay). Dùng khi tổng đài đã xác nhận trực tiếp với
     * tài xế qua điện thoại, không cần hỏi lại lần nữa qua app.
     *
     * Vẫn kiểm tra các điều kiện an toàn cơ bản (đúng thành phố, không nợ
     * quá hạn, đủ bằng lái, không bận ≥2 đơn) trước khi gán — chỉ bỏ qua
     * bước "chờ tài xế bấm nhận", không bỏ qua kiểm tra hợp lệ.
     *
     * @return array{success: bool, message: string}
     */
    public function assign(Order $order, int $driverId): array
    {
        $driver = User::with('debts')->find($driverId);

        if (!$driver || $driver->user_type !== 'driver') {
            return ['success' => false, 'message' => 'Không tìm thấy tài xế.'];
        }
        if ($driver->city_id !== $order->city_id) {
            return ['success' => false, 'message' => 'Tài xế không thuộc thành phố của đơn này.'];
        }
        if ($this->hasBlockedDebt($driver)) {
            return ['success' => false, 'message' => "Tài xế {$driver->name} đang nợ quá hạn, không thể nhận đơn."];
        }
        if ($order->service_type === 'car' && !$driver->has_car_license) {
            return ['success' => false, 'message' => "Tài xế {$driver->name} chưa có bằng lái ô tô."];
        }

        $activeCount = Order::where('delivery_man_id', $driver->id)
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->count();
        if ($activeCount >= 2) {
            return ['success' => false, 'message' => "Tài xế {$driver->name} đang chạy {$activeCount} đơn, không nhận thêm được."];
        }

        $now      = now();
        $affected = DB::table('orders')
            ->where('id', $order->id)
            ->where('status', 'pending')
            ->update([
                'status'                   => 'assigned',
                'delivery_man_id'          => $driver->id,
                'dispatching_to_driver_id' => null,
                'updated_at'               => $now,
            ]);

        if (!$affected) {
            return ['success' => false, 'message' => 'Đơn không còn ở trạng thái chờ (có thể vừa được xử lý).'];
        }

        // Chụp lại NGAY LÚC GÁN xem thành phố có đang bật chế độ trời mưa
        // không — khoá giá trị cho đơn, không đổi theo trạng thái mưa hiện
        // tại nữa dù sau đó tắt/bật lại giữa chừng.
        if (\Modules\Core\Models\City::where('id', $order->city_id)->value('is_rain_mode')) {
            DB::table('orders')->where('id', $order->id)->update(['rain_bonus_eligible' => true]);
        }

        // Ghi lại như 1 dòng "accepted" bình thường — để lên báo cáo/lịch sử
        // dispatch không bị thiếu, dù đây là gán tay bỏ qua bước offer.
        OrderDispatchLog::create([
            'order_id'     => $order->id,
            'driver_id'    => $driver->id,
            'offered_at'   => $now,
            'responded_at' => $now,
            'result'       => 'accepted',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        Redis::del("dispatch:lock:driver:{$driver->id}");
        DB::table('users')->where('id', $driver->id)->update([
            'last_order_accepted_at' => $now,
        ]);

        // RTDB + FCM đồng bộ khách hàng — giống hệt luồng accept bình thường
        // (đơn call-center thường không có tài khoản khách nên các bước này
        // tự no-op nếu sender_platform_id null).
        RTDBService::updateOrderStatus($order->code, 'assigned');
        $customer = User::find($order->sender_platform_id);
        if ($customer?->fcm_token) {
            FCMService::getInstance()->sendOrderStatusUpdate($customer->fcm_token, $order->code, 'assigned');
        }
        if ($customer) {
            CustomerNotificationController::create(
                $customer->id,
                "Đơn #{$order->code}",
                'Tài xế đã nhận đơn và đang trên đường đến',
                'order_status',
                $order->code
            );
        }

        // Báo cho tài xế biết họ vừa được gán đơn — không phải offer chờ
        // bấm nhận, chỉ là thông báo để họ mở app thấy đơn trong mục "Đã
        // nhận" ngay.
        if ($driver->fcm_token) {
            try {
                FCMService::getInstance()->sendDriverNotice(
                    $driver->fcm_token,
                    "Bạn được gán đơn mới #{$order->code}",
                    $order->pickup_address ?? '',
                    ['type' => 'order_assigned_direct', 'order_id' => (string) $order->id]
                );
            } catch (\Throwable $e) {
                Log::error("[Dispatch] FCM assign-notice failed for driver #{$driver->id}: " . $e->getMessage());
            }
        }

        broadcast(new DispatchStateChanged());

        return ['success' => true, 'message' => "Đã gán đơn cho {$driver->name} — vào mục \"Đã nhận\" ngay, không cần chờ xác nhận."];
    }

    private function hasBlockedDebt(User $driver): bool
    {
        return $driver->debts->where('status', 'overdue')->isNotEmpty();
    }
}
