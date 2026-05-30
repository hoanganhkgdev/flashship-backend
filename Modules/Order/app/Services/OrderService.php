<?php
namespace Modules\Order\Services;

use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchService;
use Modules\Core\Models\User;
use Modules\Core\Services\FCMService;
use Modules\Customer\Http\Controllers\CustomerNotificationController;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Services\DriverScoreService;
use Modules\Driver\Services\DriverWalletService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function getDriverOrders(User $user): array
    {
        $assigned = Order::with('city')
            ->where('delivery_man_id', $user->id)
            ->whereIn('status', ['assigned', 'processing', 'on_the_way'])
            ->orderByDesc('id')->get();

        $completed = Order::with('city')
            ->where('delivery_man_id', $user->id)
            ->where('status', 'completed')
            ->orderByDesc('id')->paginate(20);

        return [
            'assigned'           => $assigned,
            'completed'          => $completed->items(),
            'completed_has_more' => $completed->hasMorePages(),
        ];
    }

    public function getDashboardStats(User $user): array
    {
        $today     = now()->toDateString();
        $monthStart = now()->startOfMonth();

        $todayRow = Order::where('delivery_man_id', $user->id)
            ->where('status', 'completed')
            ->whereDate('completed_at', $today)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(shipping_fee + bonus_fee), 0) as earnings')
            ->first();

        $monthCount = Order::where('delivery_man_id', $user->id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $monthStart)
            ->count();

        $ratingRow = Order::where('delivery_man_id', $user->id)
            ->where('status', 'completed')
            ->whereNotNull('driver_rating')
            ->selectRaw('AVG(driver_rating) as avg, COUNT(*) as cnt')
            ->first();

        return [
            'today_orders'   => (int) ($todayRow->cnt ?? 0),
            'today_earnings' => (int) ($todayRow->earnings ?? 0),
            'month_orders'   => (int) $monthCount,
            'rating'         => $ratingRow?->cnt > 0 ? round((float) $ratingRow->avg, 1) : null,
            'rating_count'   => (int) ($ratingRow?->cnt ?? 0),
        ];
    }

    public function acceptOrder(Order $order, User $user): array
    {
        if ($order->status !== 'pending') {
            return ['success' => false, 'message' => 'Đơn đã có người nhận hoặc không khả dụng', 'status' => 409];
        }

        $wasOffered = OrderDispatchLog::where('order_id', $order->id)
            ->where('driver_id', $user->id)
            ->where('result', 'pending')
            ->exists();
        if (!$wasOffered) {
            return ['success' => false, 'message' => 'Đơn này không được phát cho bạn.', 'status' => 403];
        }

        $activeOrders = Order::where('delivery_man_id', $user->id)->whereIn('status', ['assigned', 'processing', 'on_the_way'])->count();
        if ($activeOrders >= 1) {
            return ['success' => false, 'message' => 'Bạn đang có đơn hàng chưa hoàn thành. Vui lòng hoàn thành đơn hiện tại trước.', 'status' => 400];
        }

        if ($order->service_type === 'car' && !$user->has_car_license) {
            return ['success' => false, 'message' => 'Đơn này yêu cầu tài xế có bằng lái ô tô.', 'status' => 403];
        }

        $affected = DB::table('orders')
            ->where('id', $order->id)
            ->where('status', 'pending')
            ->update([
                'status'                   => 'assigned',
                'delivery_man_id'          => $user->id,
                'dispatching_to_driver_id' => null,
                'updated_at'               => now(),
            ]);

        if ($affected === 0) {
            return ['success' => false, 'message' => 'Đơn đã có người nhận trước bạn.', 'status' => 409];
        }

        $orderId   = $order->id;
        $userId    = $user->id;
        $orderCode = $order->code;

        // RTDB + FCM đồng bộ — customer app nhận realtime ngay lập tức
        RTDBService::updateOrderStatus($orderCode, 'assigned');
        $customer = User::find($order->sender_platform_id);
        if ($customer?->fcm_token) {
            FCMService::getInstance()->sendOrderStatusUpdate($customer->fcm_token, $orderCode, 'assigned');
        }
        if ($customer) {
            CustomerNotificationController::create(
                $customer->id,
                "Đơn #{$orderCode}",
                'Tài xế đã nhận đơn và đang trên đường đến',
                'order_status',
                $orderCode
            );
        }

        dispatch(function () use ($orderId, $userId) {
            $freshOrder = Order::find($orderId);
            if (!$freshOrder) return;
            app(DispatchService::class)->handleAccepted($freshOrder, User::find($userId));
            Log::info("✅ Driver #{$userId} accepted order #{$orderId}");
        })->afterResponse();

        return ['success' => true, 'order' => $order->fresh(), 'status' => 200];
    }

    public function declineOrder(Order $order, User $user): array
    {
        $log = OrderDispatchLog::where('order_id', $order->id)
            ->where('driver_id', $user->id)
            ->where('result', 'pending')
            ->first();

        if (!$log) {
            return ['success' => false, 'message' => 'Đơn này không được phát cho bạn.', 'status' => 403];
        }

        if ($order->status !== 'pending') {
            return ['success' => false, 'message' => 'Đơn không còn ở trạng thái chờ.', 'status' => 409];
        }

        $log->update(['result' => 'declined', 'responded_at' => now()]);

        DriverScoreService::onDecline($user->id);

        // Xóa offer RTDB ngay lập tức — app dismiss màn hình offer
        RTDBService::clearDriverOffer($user->id);

        // Chuyển ngay sang tài xế tiếp theo, không chờ job 30s
        $orderId = $order->id;
        dispatch(function () use ($orderId) {
            $freshOrder = Order::find($orderId);
            if ($freshOrder) {
                app(DispatchService::class)->sendToNextDriver($freshOrder);
            }
        })->afterResponse();

        return ['success' => true, 'message' => 'Đã từ chối đơn hàng.', 'status' => 200];
    }

    public function updateOrderStatus(Order $order, User $user, string $status): array
    {
        $allowed = ['assigned' => 'processing', 'processing' => 'on_the_way'];

        if ((int) $order->delivery_man_id !== (int) $user->id) {
            return ['success' => false, 'message' => 'Bạn không có quyền cập nhật đơn này.', 'status' => 403];
        }

        if (!isset($allowed[$order->status]) || $allowed[$order->status] !== $status) {
            return ['success' => false, 'message' => "Không thể chuyển từ {$order->status} sang {$status}.", 'status' => 400];
        }

        $order->update(['status' => $status]);

        RTDBService::updateOrderStatus($order->code, $status);

        $customer = User::find($order->sender_platform_id);
        if ($customer?->fcm_token) {
            FCMService::getInstance()->sendOrderStatusUpdate($customer->fcm_token, $order->code, $status);
        }
        if ($customer) {
            $statusLabel = match ($status) {
                'processing' => 'Tài xế đang lấy hàng',
                'on_the_way' => 'Đơn hàng đang được giao',
                'cancelled'  => 'Đơn hàng đã bị hủy',
                default      => "Đơn hàng cập nhật trạng thái",
            };
            CustomerNotificationController::create(
                $customer->id,
                "Đơn #{$order->code}",
                $statusLabel,
                'order_status',
                $order->code
            );
        }

        Log::info("🔄 Order #{$order->id} status → {$status} by driver #{$user->id}");

        return ['success' => true, 'message' => 'Cập nhật trạng thái thành công.', 'status' => 200];
    }

    public function completeOrder(Order $order, User $user): array
    {
        if ((int) $order->delivery_man_id !== (int) $user->id) {
            return ['success' => false, 'message' => 'Bạn không có quyền hoàn thành đơn này.', 'status' => 403];
        }

        if ($order->status === 'cancelled') {
            return ['success' => false, 'message' => 'Đơn hàng đã bị hủy.', 'status' => 400];
        }

        if ($order->status === 'completed') {
            return ['success' => true, 'message' => 'Đơn này đã hoàn thành trước đó.', 'data' => $order, 'status' => 200];
        }

        $order->update(['status' => 'completed', 'completed_at' => now(), 'delivered_at' => now()]);

        $shippingFee = (float) ($order->shipping_fee ?? 0);
        $bonusFee    = (float) ($order->bonus_fee ?? 0);
        $discountAmt = (float) ($order->discount_amount ?? 0);

        if ($order->is_freeship && $shippingFee > 0) {
            DriverWalletService::adjust($user->id, $shippingFee, 'credit', "Ship Freeship #{$order->id}", "order_{$order->id}_shipping");
        }

        if ($discountAmt > 0) {
            DriverWalletService::adjust($user->id, $discountAmt, 'credit', "Bù giảm giá đơn #{$order->id}", "order_{$order->id}_discount");
        }

        if ($bonusFee > 0) {
            DriverWalletService::adjust($user->id, $bonusFee, 'credit', "Bonus #{$order->id}", "order_{$order->id}_bonus");
        }

        DriverScoreService::onComplete($user->id);

        Cache::forget("driver_stats_{$user->id}");
        Log::info("✅ Order #{$order->id} completed by driver #{$user->id}");

        RTDBService::clearOrder($order->code);
        $customer = User::find($order->sender_platform_id);
        if ($customer?->fcm_token) {
            FCMService::getInstance()->sendOrderStatusUpdate($customer->fcm_token, $order->code, 'completed');
        }
        if ($customer) {
            CustomerNotificationController::create(
                $customer->id,
                "Đơn #{$order->code}",
                'Đơn hàng đã được giao thành công',
                'order_status',
                $order->code
            );
        }

        return ['success' => true, 'message' => 'Hoàn thành đơn thành công', 'data' => $order->fresh(), 'status' => 200];
    }

    public function createOrder(array $data, User $user): Order
    {
        return Order::create([
            'code'             => '',
            'service_type'     => $data['service_type'],
            'order_note'       => $data['order_note'] ?? null,
            'city_id'          => $user->city_id,
            'shipping_fee'     => $data['shipping_fee'] ?? 0,
            'bonus_fee'        => 0,
            'is_freeship'      => false,
            'status'           => 'pending',
            'platform'         => $data['platform'] ?? 'customer_app',
            'sender_platform_id' => $user->id,
            'pickup_address'   => $data['pickup_address'] ?? null,
            'pickup_lat'       => $data['pickup_lat'] ?? null,
            'pickup_lng'       => $data['pickup_lng'] ?? null,
            'pickup_phone'     => $data['pickup_phone'] ?? null,
            'sender_name'      => $data['sender_name'] ?? null,
            'delivery_address' => $data['delivery_address'] ?? null,
            'delivery_lat'     => $data['delivery_lat'] ?? null,
            'delivery_lng'     => $data['delivery_lng'] ?? null,
            'delivery_phone'   => $data['delivery_phone'] ?? null,
            'receiver_name'    => $data['receiver_name'] ?? null,
            'distance'         => $data['distance'] ?? null,
            'payment_method'   => $data['payment_method'] ?? 'cod',
            'cod_amount'       => $data['cod_amount'] ?? null,
            'store_name'       => $data['store_name'] ?? null,
            'scheduled_at'     => $data['scheduled_at'] ?? null,
        ]);
    }

    public function dispatchNewOrder(int $orderId): void
    {
        $order = Order::find($orderId);
        if (!$order) return;

        if ($order->status === 'pending') {
            app(DispatchService::class)->startDispatch($order);
        } elseif ($order->status === 'assigned' && $order->delivery_man_id) {
            // TODO: Firebase publishOrder($order)
        }
    }

    public function getCompletedOrders(User $user, int $page = 1, int $perPage = 20): array
    {
        $paginator = Order::with('city')
            ->where('delivery_man_id', $user->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'completed'    => $paginator->items(),
            'has_more'     => $paginator->hasMorePages(),
            'current_page' => $paginator->currentPage(),
            'total'        => $paginator->total(),
        ];
    }

    public function getWeeklyEarnings(int $driverId): array
    {
        $start = Carbon::now()->startOfWeek();
        return $this->buildDailyEarnings($driverId, $start, $start->copy()->endOfWeek(), 7);
    }

    public function getMonthlyEarnings(int $driverId): array
    {
        $start = Carbon::now()->startOfMonth();
        return $this->buildDailyEarnings($driverId, $start, $start->copy()->endOfMonth(), $start->daysInMonth);
    }

    private function buildDailyEarnings(int $driverId, Carbon $start, Carbon $end, int $days): array
    {
        $data = DB::table('orders')
            ->selectRaw('DATE(completed_at) as date, SUM(shipping_fee) as shipping, SUM(bonus_fee) as bonus')
            ->where('delivery_man_id', $driverId)->where('status', 'completed')
            ->whereBetween('completed_at', [$start, $end])
            ->groupBy('date')->get()->keyBy('date');

        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $day      = $start->copy()->addDays($i)->toDateString();
            $row      = $data->get($day);
            $result[] = [
                'date'     => $day,
                'total'    => (float) (($row->shipping ?? 0) + ($row->bonus ?? 0)),
                'shipping' => (float) ($row->shipping ?? 0),
                'bonus'    => (float) ($row->bonus ?? 0),
            ];
        }

        return $result;
    }

    public function getRecentOrders(int $driverId): array
    {
        return Order::where('delivery_man_id', $driverId)->orderByDesc('id')->take(5)
            ->get(['id', 'status', 'shipping_fee', 'bonus_fee', 'created_at'])
            ->map(fn($o) => [
                'id' => $o->id, 'status' => $o->status,
                'shipping_fee' => $o->shipping_fee, 'bonus_fee' => $o->bonus_fee,
                'created_at' => $o->created_at->toDateTimeString(),
            ])->toArray();
    }

    public function getKpi(User $driver): array
    {
        $start = Carbon::now()->startOfWeek();
        $end   = Carbon::now()->endOfWeek();

        $row = Order::where('delivery_man_id', $driver->id)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("COUNT(*) as orders_done,
                SUM(CASE WHEN status='completed' THEN shipping_fee ELSE 0 END) as earnings_shipping,
                SUM(CASE WHEN status='completed' THEN bonus_fee ELSE 0 END) as earnings_bonus")
            ->first();

        $shipping = (float) ($row->earnings_shipping ?? 0);
        $bonus    = (float) ($row->earnings_bonus ?? 0);

        return [
            'orders_done'       => (int) ($row->orders_done ?? 0),
            'orders_target'     => 20,
            'earnings_done'     => $shipping + $bonus,
            'earnings_shipping' => $shipping,
            'earnings_bonus'    => $bonus,
            'earnings_target'   => 2000000,
        ];
    }

    public function getEarningsSummary(int $driverId, string $period): array
    {
        $start = match ($period) {
            'week'  => Carbon::now()->startOfWeek(),
            'today' => Carbon::now()->startOfDay(),
            default => Carbon::now()->startOfMonth(),
        };
        $end = match ($period) {
            'week'  => Carbon::now()->endOfWeek(),
            'today' => Carbon::now()->endOfDay(),
            default => Carbon::now()->endOfMonth(),
        };

        $row = Order::where('delivery_man_id', $driverId)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$start, $end])
            ->selectRaw('COUNT(*) as orders, SUM(shipping_fee + bonus_fee) as total')
            ->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'total'  => (int) ($row->total ?? 0),
        ];
    }
}
