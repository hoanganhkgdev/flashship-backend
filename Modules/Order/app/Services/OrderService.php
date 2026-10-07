<?php
namespace Modules\Order\Services;

use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchService;
use Modules\Order\Services\OrderTimeline;
use Modules\Core\Models\User;
use Modules\Core\Models\Voucher;
use Modules\Core\Models\VoucherUsage;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\GoogleMapService;
use Modules\Core\Services\OperationalSettings;
use Modules\Customer\Http\Controllers\CustomerNotificationController;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Services\DriverScoreService;
use Modules\Driver\Services\DriverLocationService;
use Modules\Driver\Services\DriverWalletService;
use Modules\Driver\Services\ReferralService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class OrderService
{
    // Thưởng thêm khi tài xế nhận đơn lúc thành phố đang bật chế độ trời mưa
    // (xem rain_bonus_eligible ở acceptOrder()) — 1 nguồn duy nhất cho cả số
    // tiền cộng thật (completeOrder()) lẫn số tiền xem trước gửi kèm offer
    // (DispatchOfferSender::commitOffer()), tránh lệch nếu sau này đổi giá trị.
    /**
     * Hủy một đơn còn pending theo một quy tắc duy nhất cho customer/shop.
     * Trạng thái đơn, dispatch log và hoàn lượt voucher commit cùng nhau.
     *
     * @return array{success: bool, message: string}
     */
    public function cancelPendingOrder(Order $order, ?string $reason = null, ?string $note = null, ?int $by = null): array
    {
        [$reason, $by] = $this->cancelActor($reason, $by);
        $result = DB::transaction(function () use ($order, $reason, $note, $by) {
            $fresh = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status !== 'pending') {
                return ['success' => false, 'driver_id' => null, 'order' => $fresh];
            }

            $driverId = $fresh->dispatching_to_driver_id
                ? (int) $fresh->dispatching_to_driver_id
                : null;

            $fresh->update([
                'status' => 'cancelled',
                'dispatching_to_driver_id' => null,
                'cancel_reason' => $reason ?? $fresh->cancel_reason,
                'cancel_note' => $note,
                'cancelled_by' => $by,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);
            OrderTimeline::record($fresh, 'cancelled', 'Hủy đơn — '.OrderTimeline::cancelReasonLabel($reason ?? $fresh->cancel_reason).($note ? ': '.$note : ''), $by);

            OrderDispatchLog::where('order_id', $fresh->id)
                ->where('result', 'pending')
                ->update(['result' => 'expired', 'responded_at' => now()]);

            // Hoàn đúng usage của đơn; chỉ giảm used_count nếu thật sự xóa
            // được usage, nên request hủy lặp không thể hoàn voucher hai lần.
            $this->releaseVoucherUsage($fresh->id);

            return ['success' => true, 'driver_id' => $driverId, 'order' => $fresh];
        });

        if (!$result['success']) {
            return ['success' => false, 'message' => 'Chỉ có thể hủy đơn khi chưa có tài xế nhận'];
        }

        /** @var Order $cancelled */
        $cancelled = $result['order'];
        app(OrderMarketService::class)->close($cancelled);
        try {
            RTDBService::clearOrder($cancelled->code);

            $driverId = $result['driver_id'];
            if ($driverId) {
                RTDBService::clearDriverOffer($driverId, $cancelled->id);
                Redis::eval(
                    "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                    1,
                    "dispatch:lock:driver:{$driverId}",
                    (string) $cancelled->id,
                );
            }
            Redis::del("dispatch:retry_pending:{$cancelled->id}");
        } catch (\Throwable $e) {
            // DB đã commit là nguồn sự thật; lỗi cleanup realtime/cache không
            // được biến lần hủy thành HTTP 500 khiến client thử hủy lặp.
            Log::warning('[OrderCancel] realtime cleanup failed', [
                'order_id' => $cancelled->id,
                'message' => $e->getMessage(),
            ]);
        }

        return ['success' => true, 'message' => 'Đã hủy đơn hàng'];
    }

    /**
     * Ai hủy và vì sao: nếu không truyền, lấy người đang đăng nhập; khách/cửa hàng hủy trong app thì lý do là 'customer'.
     *
     * @return array{0:?string,1:?int}
     */
    private function cancelActor(?string $reason, ?int $by): array
    {
        $user = $by ? null : request()->user();
        $by ??= $user?->id;
        if ($reason === null && $user && in_array($user->user_type, ['customer', 'shop'], true)) {
            $reason = 'customer';
        }

        return [$reason, $by];
    }

    /**
     * Cancel an active order from admin and restore its voucher atomically.
     *
     * processing is intentionally accepted here: driver earnings, freeship and
     * rain bonuses are only credited by completeOrder(), so cancelling before
     * completion prevents a false payout while preserving the audit trail.
     */
    public function cancelActiveOrderByAdmin(Order $order, ?string $reason = null, ?string $note = null, ?int $by = null): array
    {
        [$reason, $by] = $this->cancelActor($reason ?? 'admin', $by);

        return DB::transaction(function () use ($order, $reason, $note, $by) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($fresh->status, ['assigned', 'processing'], true)) {
                return ['success' => false, 'message' => 'Đơn không còn ở trạng thái đang thực hiện'];
            }

            $previousStatus = $fresh->status;

            $fresh->update([
                'status' => 'cancelled',
                'cancel_reason' => $reason,
                'cancel_note' => $note,
                'cancelled_by' => $by,
                'cancelled_at' => now(),
                'dispatching_to_driver_id' => null,
                'updated_at' => now(),
            ]);
            $stage = $previousStatus === 'processing' ? 'đã lấy hàng' : 'đã nhận';
            OrderTimeline::record($fresh, 'cancelled', "Hủy đơn {$stage} — ".OrderTimeline::cancelReasonLabel($reason).($note ? ': '.$note : ''), $by);
            $this->releaseVoucherUsage($fresh->id);

            return ['success' => true, 'message' => 'Đã hủy đơn hàng'];
        });
    }

    /** @deprecated Use cancelActiveOrderByAdmin(). */
    public function cancelAssignedOrderByAdmin(Order $order, ?string $reason = null, ?string $note = null, ?int $by = null): array
    {
        return $this->cancelActiveOrderByAdmin($order, $reason, $note, $by);
    }

    /** Must be called inside a DB transaction. */
    private function releaseVoucherUsage(int $orderId): void
    {
        $usage = VoucherUsage::where('order_id', $orderId)
            ->lockForUpdate()
            ->first();
        if (! $usage) {
            return;
        }

        $voucherId = $usage->voucher_id;
        $usage->delete();
        Voucher::where('id', $voucherId)
            ->where('used_count', '>', 0)
            ->decrement('used_count');
    }

    public function getDriverOrders(User $user): array
    {
        $assigned = Order::with('city')
            ->where('delivery_man_id', $user->id)
            ->whereIn('status', ['assigned', 'processing'])
            ->orderByDesc('id')->get();

        $completed = Order::with('city')
            ->where('delivery_man_id', $user->id)
            ->where('status', 'completed')
            ->orderByDesc('id')->paginate(20);

        return [
            'assigned'           => $assigned->map(fn($o) => $this->formatOrderForDriver($o))->values()->all(),
            'completed'          => array_map(fn($o) => $this->formatOrderForDriver($o), $completed->items()),
            'completed_has_more' => $completed->hasMorePages(),
        ];
    }

    public function formatOrderForDriver(Order $order): array
    {
        return [
            'id'               => $order->id,
            'code'             => $order->code,
            'service_type'     => $order->service_type,
            'status'           => $order->status,
            'pickup_name'         => $order->sender_name         ?? '',
            'pickup_place_name'   => $order->pickup_place_name   ?? null,
            'pickup_address'      => $order->pickup_address      ?? '',
            'pickup_phone'        => $order->pickup_phone        ?? '',
            'pickup_lat'          => $order->pickup_lat          ? (float) $order->pickup_lat  : null,
            'pickup_lng'          => $order->pickup_lng          ? (float) $order->pickup_lng  : null,
            'delivery_place_name' => $order->delivery_place_name ?? null,
            'delivery_address'    => $order->delivery_address    ?? '',
            'delivery_phone'      => $order->delivery_phone      ?? '',
            'receiver_name'       => $order->receiver_name       ?? '',
            'delivery_lat'     => $order->delivery_lat     ? (float) $order->delivery_lat : null,
            'delivery_lng'     => $order->delivery_lng     ? (float) $order->delivery_lng : null,
            'order_note'       => $order->order_note       ?? '',
            'store_name'       => $order->store_name       ?? '',
            'platform'         => $order->platform         ?? 'customer_app',
            'shop_service_type' => $order->shop_service_type ?? null,
            'cargo_type'       => $order->cargo_type       ?? 'food',
            'cargo_note'       => $order->cargo_note       ?? null,
            'cargo_weight'     => $order->cargo_weight     ? (float) $order->cargo_weight : null,
            'is_batch'         => (bool) ($order->is_batch ?? false),
            'stops_count'      => $order->is_batch ? count($order->stops ?? []) : 0,
            'stops'            => $order->is_batch ? ($order->stops ?? []) : [],
            'shipping_fee'     => (int) ($order->shipping_fee    ?? 0),
            'bonus_fee'        => (int) ($order->bonus_fee       ?? 0),
            'night_surcharge'  => (int) ($order->night_surcharge ?? 0),
            'rain_bonus_eligible' => (bool) ($order->rain_bonus_eligible ?? false),
            'rain_bonus_amount'   => $order->rain_bonus_eligible ? ($order->rain_bonus_amount ?? OperationalSettings::rainBonusAmount($order->city_id)) : 0,
            'discount_amount'  => (int) ($order->discount_amount ?? 0),
            'voucher_code'     => $order->voucher_code ?? null,
            'payment_method'   => $order->payment_method ?? 'prepaid',
            'cod_amount'       => (int) ($order->cod_amount ?? 0),
            'created_at'       => $order->created_at?->toIso8601String(),
            'completed_at'     => $order->completed_at?->toIso8601String(),
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
            ->where('rating_hidden', false)
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

        if ($order->service_type === 'car' && !$user->has_car_license) {
            return ['success' => false, 'message' => 'Đơn này yêu cầu tài xế có bằng lái ô tô.', 'status' => 403];
        }

        $assignment = DB::transaction(function () use ($order, $user) {
            // Khóa tài xế là chốt nghiệp vụ chung cho accept từ app và gán
            // tay từ tổng đài. Hai luồng không thể cùng đếm activeCount cũ
            // rồi cùng gán vượt giới hạn đơn active.
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $activeOrders = Order::where('delivery_man_id', $user->id)
                ->whereIn('status', ['assigned', 'processing'])
                ->lockForUpdate()
                ->get();
            if ($activeOrders->isNotEmpty()) {
                OrderDispatchLog::where('order_id', $order->id)
                    ->where('driver_id', $user->id)
                    ->where('result', 'pending')
                    // Enum DB chỉ có pending/accepted/declined/expired.
                    // Đây là offer không còn hợp lệ do tài xế vừa đạt trần đơn,
                    // không phải hành vi từ chối để bị trừ điểm.
                    ->update(['result' => 'expired', 'responded_at' => now()]);
                DB::table('orders')->where('id', $order->id)
                    ->where('status', 'pending')
                    ->where('dispatching_to_driver_id', $user->id)
                    ->update(['dispatching_to_driver_id' => null, 'updated_at' => now()]);

                return 'busy';
            }

            return DB::table('orders')
                ->where('id', $order->id)
                ->where('status', 'pending')
                // Log pending cũ chưa đủ quyền nhận đơn: timeout có thể vừa
                // chuyển offer sang người khác. Chỉ đúng tài xế mà order
                // hiện đang trỏ tới mới được accept.
                ->where('dispatching_to_driver_id', $user->id)
                ->update([
                    'status'                   => 'assigned',
                    'delivery_man_id'          => $user->id,
                    'dispatching_to_driver_id' => null,
                    'updated_at'               => now(),
                ]);
        });

        if ($assignment === 'busy') {
            Redis::del("dispatch:lock:driver:{$user->id}");
            RTDBService::clearDriverOffer($user->id, $order->id);
            $orderId = $order->id;
            dispatch(function () use ($orderId) {
                $freshOrder = Order::find($orderId);
                if ($freshOrder?->status === 'pending') {
                    app(DispatchService::class)->sendToNextDriver($freshOrder);
                }
            })->afterResponse();

            $message = 'Bạn đã có đơn đang thực hiện. Offer tự động chỉ dành cho tài xế đang rảnh; bạn có thể chủ động nhận thêm trong Chợ đơn.';

            return ['success' => false, 'message' => $message, 'status' => 409];
        }

        if ($assignment === 0) {
            return ['success' => false, 'message' => 'Đơn đã có người nhận trước bạn.', 'status' => 409];
        }

        // Chụp lại NGAY LÚC NHẬN xem thành phố có đang bật chế độ trời mưa
        // không — khoá giá trị này cho đơn, không đổi theo trạng thái mưa
        // hiện tại nữa dù sau đó tắt/bật lại giữa chừng (tài xế nhận lúc mưa
        // thì được thưởng, dù giao xong trời đã tạnh).
        if (\Modules\Core\Models\City::where('id', $order->city_id)->value('is_rain_mode')) {
            DB::table('orders')->where('id', $order->id)->update([
                'rain_bonus_eligible' => true,
                'rain_bonus_amount' => OperationalSettings::rainBonusAmount($order->city_id),
            ]);
        }

        \Illuminate\Support\Facades\Redis::del("dispatch:lock:driver:{$user->id}");
        DB::table('users')->where('id', $user->id)->update([
            'last_order_accepted_at' => now(),
        ]);
        app(OrderMarketService::class)->close($order);

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

        return ['success' => true, 'order' => $this->formatOrderForDriver($order->fresh()), 'status' => 200];
    }

    public function declineOrder(Order $order, User $user): array
    {
        if ($order->status !== 'pending') {
            return ['success' => false, 'message' => 'Đơn không còn ở trạng thái chờ.', 'status' => 409];
        }

        $log = DB::transaction(function () use ($order, $user) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$fresh || $fresh->status !== 'pending'
                || (int) $fresh->dispatching_to_driver_id !== (int) $user->id) {
                return null;
            }

            $log = OrderDispatchLog::where('order_id', $order->id)
                ->where('driver_id', $user->id)
                ->where('result', 'pending')
                ->lockForUpdate()
                ->first();

            if (!$log) return null;

            $log->update(['result' => 'declined', 'responded_at' => now()]);
            $fresh->update([
                'dispatching_to_driver_id' => null,
                'offer_viewed_at' => null,
                'updated_at' => now(),
            ]);
            return $log;
        });

        if (!$log) {
            return ['success' => false, 'message' => 'Đơn này không được phát cho bạn.', 'status' => 403];
        }

        \Illuminate\Support\Facades\Redis::del("dispatch:lock:driver:{$user->id}");

        DriverScoreService::onDecline($user->id);

        RTDBService::clearDriverOffer($user->id, $order->id);

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
        // Flow vận hành: assigned → processing → completed.
        $allowed = ['assigned' => 'processing'];

        if ((int) $order->delivery_man_id !== (int) $user->id) {
            return ['success' => false, 'message' => 'Bạn không có quyền cập nhật đơn này.', 'status' => 403];
        }

        if (!isset($allowed[$order->status]) || $allowed[$order->status] !== $status) {
            return ['success' => false, 'message' => "Không thể chuyển từ {$order->status} sang {$status}.", 'status' => 400];
        }

        $order->update(['status' => $status]);
        OrderTimeline::record($order, 'picked_up', 'Tài xế đã lấy hàng', $user->id);

        RTDBService::updateOrderStatus($order->code, $status);

        $customer = User::find($order->sender_platform_id);
        if ($customer?->fcm_token) {
            FCMService::getInstance()->sendOrderStatusUpdate($customer->fcm_token, $order->code, $status);
        }
        if ($customer) {
            $statusLabel = match ($status) {
                'processing' => 'Tài xế đã lấy hàng',
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

    public function completeOrder(Order $order, User $user, bool $proximityAlreadyVerified = false): array
    {
        if ((int) $order->delivery_man_id !== (int) $user->id) {
            return ['success' => false, 'message' => 'Bạn không có quyền hoàn thành đơn này.', 'status' => 403];
        }

        if ($order->status === 'completed') {
            return ['success' => true, 'message' => 'Đơn này đã hoàn thành trước đó.', 'data' => $order, 'status' => 200];
        }

        $target = $this->completionTarget($order);
        $hasTarget = is_numeric($target['lat'] ?? null) && is_numeric($target['lng'] ?? null);

        // Đơn không có toạ độ điểm giao (thường là đơn tổng đài chở người hoặc
        // chưa biết điểm đến lúc đặt) thì không có gì để so vị trí — cho hoàn
        // thành thay vì chặn tài xế vô thời hạn; ghi log để theo dõi.
        if (! $proximityAlreadyVerified && ! $hasTarget) {
            Log::info('[OrderComplete] no delivery point, proximity check skipped', [
                'order_id' => $order->id,
                'driver_id' => $user->id,
                'platform' => $order->platform,
                'service_type' => $order->service_type,
            ]);
        }

        if (! $proximityAlreadyVerified && $hasTarget) {
            $proximityError = $this->checkDriverProximity($user, $target['lat'], $target['lng'], $order->city_id);
            if ($proximityError) {
                Log::warning('[OrderComplete] proximity rejected', [
                    'order_id' => $order->id,
                    'driver_id' => $user->id,
                    'reason' => $proximityError['reason_code'],
                    'distance_m' => $proximityError['distance_m'] ?? null,
                ]);

                return $proximityError;
            }
        }

        $completion = DB::transaction(function () use ($order, $user, $proximityAlreadyVerified) {
            $fresh = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ((int) $fresh->delivery_man_id !== (int) $user->id) {
                return ['error' => 'forbidden'];
            }
            if ($fresh->status === 'completed') {
                return ['already' => true];
            }
            if ($fresh->status === 'cancelled') {
                return ['error' => 'cancelled'];
            }
            // Cả đơn thường và đơn gộp đều phải qua bước Đã lấy hàng.
            if ($fresh->status !== 'processing') {
                return ['error' => 'not_processing'];
            }

            if ($fresh->is_batch) {
                $stops = $fresh->stops ?? [];
                $allDelivered = count($stops) > 0
                    && collect($stops)->every(fn ($s) => ($s['delivered_at'] ?? null) !== null);
                if (!$allDelivered) {
                    return ['error' => 'batch_incomplete'];
                }
            }

            $completedAt = now();
            $fresh->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'delivered_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);
            $staff = $proximityAlreadyVerified ? request()->user() : null;
            OrderTimeline::record($fresh, 'completed', 'Hoàn thành đơn'.($staff && $staff->id !== $user->id ? ' (nhân viên xác nhận thay tài xế)' : ''), $staff?->id ?? $user->id);

            // Tất cả quyền lợi tài xế commit/rollback cùng trạng thái đơn.
            // Nếu bất kỳ bước nào lỗi, đơn vẫn processing và có thể thử lại,
            // không tồn tại đơn completed nhưng thiếu tiền/điểm.
            $shippingFee = (float) ($fresh->shipping_fee ?? 0);
            $bonusFee = (float) ($fresh->bonus_fee ?? 0);
            $discountAmt = (float) ($fresh->discount_amount ?? 0);

            // shipping_fee is the amount the driver collects directly from the
            // customer. Crediting it to the wallet as well would pay that amount
            // twice. For a freeship/discount voucher the platform only owes the
            // subsidised part recorded in discount_amount. Call-center freeship
            // has no voucher/discount and remains platform-paid in full.
            if ($fresh->is_freeship && empty($fresh->voucher_code) && $discountAmt <= 0 && $shippingFee > 0) {
                DriverWalletService::adjust($user->id, $shippingFee, 'credit', "Ship Freeship #{$fresh->id}", "order_{$fresh->id}_shipping");
            }
            if ($discountAmt > 0) {
                DriverWalletService::adjust($user->id, $discountAmt, 'credit', "Bù giảm giá đơn #{$fresh->id}", "order_{$fresh->id}_discount");
            }
            if ($bonusFee > 0) {
                DriverWalletService::adjust($user->id, $bonusFee, 'credit', "Bonus #{$fresh->id}", "order_{$fresh->id}_bonus");
            }
            $rainBonusAmount = $fresh->rain_bonus_amount ?? OperationalSettings::rainBonusAmount($fresh->city_id);
            if ($fresh->rain_bonus_eligible && $rainBonusAmount > 0) {
                DriverWalletService::adjust($user->id, $rainBonusAmount, 'credit', "Thưởng trời mưa #{$fresh->id}", "order_{$fresh->id}_rain");
            }

            // cod_amount chỉ là số tiền shop báo để tài xế chuẩn bị ứng khi
            // lấy hàng và thu lại từ khách. Shop và tài xế tự đối soát khoản
            // này ngoài hệ thống, nên hoàn tất đơn không được tạo công nợ.

            // Thưởng giới thiệu là phụ: lỗi ở đây không được làm hỏng việc hoàn thành đơn.
            try {
                ReferralService::onOrderCompleted($fresh);
            } catch (\Throwable $e) {
                Log::error('[Referral] reward failed', ['order_id' => $fresh->id, 'error' => $e->getMessage()]);
            }
            try {
                \Modules\Shop\Services\ShopReferralService::onOrderCompleted($fresh);
            } catch (\Throwable $e) {
                Log::error('[ShopReferral] reward failed', ['order_id' => $fresh->id, 'error' => $e->getMessage()]);
            }

            DriverScoreService::onComplete($user->id);
            DB::table('users')->where('id', $user->id)->update([
                'last_order_completed_at' => $completedAt,
            ]);

            return ['order' => $fresh->fresh()];
        });

        if (isset($completion['error'])) {
            return match ($completion['error']) {
                'forbidden' => ['success' => false, 'message' => 'Bạn không có quyền hoàn thành đơn này.', 'status' => 403],
                'cancelled' => ['success' => false, 'message' => 'Đơn hàng đã bị hủy.', 'status' => 400],
                'batch_incomplete' => ['success' => false, 'message' => 'Đơn gộp cần giao hết các điểm trước khi hoàn thành.', 'status' => 400],
                default => ['success' => false, 'message' => 'Đơn hàng cần ở trạng thái đã lấy hàng mới hoàn thành được.', 'status' => 400],
            };
        }

        if (isset($completion['already'])) {
            return ['success' => true, 'message' => 'Đơn này đã hoàn thành trước đó.', 'data' => $order->fresh(), 'status' => 200];
        }

        $order = $completion['order'];

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

    /** $cityId: khu vực của đơn — bán kính hoàn thành theo cấu hình khu vực đó. */
    public function checkDriverProximity(User $driver, mixed $targetLat, mixed $targetLng, ?int $cityId): ?array
    {
        if (! is_numeric($targetLat) || ! is_numeric($targetLng)) {
            return [
                'success' => false,
                'reason_code' => 'DELIVERY_LOCATION_MISSING',
                'message' => 'Đơn hàng thiếu tọa độ điểm giao. Vui lòng liên hệ tổng đài.',
                'status' => 422,
            ];
        }

        $locations = app(DriverLocationService::class)->freshLocationsFor([(int) $driver->id]);
        $location = $locations[(int) $driver->id] ?? null;
        if (! $location) {
            return [
                'success' => false,
                'reason_code' => 'DRIVER_LOCATION_UNAVAILABLE',
                'message' => 'Không xác minh được vị trí hiện tại. Vui lòng bật GPS và thử lại.',
                'status' => 422,
            ];
        }

        $distanceKm = GoogleMapService::haversineKm(
            (float) $location['lat'],
            (float) $location['lng'],
            (float) $targetLat,
            (float) $targetLng,
        );
        if ($distanceKm > OperationalSettings::completionRadiusKm($cityId)) {
            $distanceM = (int) round($distanceKm * 1000);
            return [
                'success' => false,
                'reason_code' => 'TOO_FAR_FROM_DELIVERY',
                'message' => "Hệ thống ghi nhận bạn đang cách điểm giao {$distanceM} m. Cần ở trong phạm vi ".OperationalSettings::completionRadiusMeters($cityId).' m để hoàn thành.',
                'distance_m' => $distanceM,
                'status' => 422,
            ];
        }

        return null;
    }

    /** @return array{lat: mixed, lng: mixed} */
    private function completionTarget(Order $order): array
    {
        if ($order->is_batch) {
            $lastStop = collect($order->stops ?? [])->sortByDesc('seq')->first();
            if ($lastStop) {
                return ['lat' => $lastStop['lat'] ?? null, 'lng' => $lastStop['lng'] ?? null];
            }
        }

        return ['lat' => $order->delivery_lat, 'lng' => $order->delivery_lng];
    }

    public function createOrder(array $data, User $user): Order
    {
        if (empty($data['pickup_lat']) || empty($data['pickup_lng'])) {
            throw new \InvalidArgumentException('Thiếu toạ độ điểm lấy hàng. Vui lòng chọn địa chỉ từ bản đồ.');
        }

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
            'completed'    => array_map(fn($o) => $this->formatOrderForDriver($o), $paginator->items()),
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
